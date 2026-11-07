<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PlatformFeeConfig;
use App\Models\School;
use App\Models\User;
use App\Models\WorkspaceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $student;

    private User $schoolAdmin;

    private User $admin;

    private User $ngo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Workspace Academy',
            'slug' => 'workspace-academy',
            'verification_status' => 'verified',
        ]);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->ngo = User::factory()->create(['role' => 'ngo']);

        PlatformFeeConfig::create([
            'name' => 'Standard fee',
            'percentage_rate' => 0.10,
            'fixed_fee_minor' => 0,
            'min_fee_minor' => 0,
            'currency' => 'NGN',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    public function test_workspace_returns_the_students_own_financial_records(): void
    {
        Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-WS-001',
            'amount_minor' => 150000,
            'description' => 'Term fees',
            'status' => 'issued',
        ]);

        LedgerEntry::create([
            'school_id' => $this->school->id,
            'user_id' => $this->student->id,
            'entry_type' => 'credit',
            'amount_minor' => 50000,
            'currency' => 'NGN',
            'source_type' => 'adjustment',
            'source_id' => 'adj-1',
            'idempotency_key' => 'adj-1',
            'occurred_at' => now(),
        ]);

        Campaign::create([
            'slug' => 'published-campaign',
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'title' => 'Published campaign',
            'story' => 'Story',
            'student_name' => $this->student->name,
            'net_base_goal_minor' => 90000,
            'gross_target_minor' => 100000,
            'gross_raised_minor' => 40000,
            'status' => 'active',
            'published_at' => now(),
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/workspace')
            ->assertOk()
            ->assertJsonPath('workspace.wallet.available_minor', 50000)
            ->assertJsonPath('workspace.invoices.0.reference', 'INV-WS-001')
            ->assertJsonPath('workspace.invoices.0.outstanding_minor', 150000)
            ->assertJsonPath('workspace.campaigns.0.slug', 'published-campaign')
            ->assertJsonPath('workspace.fee_config.percentage_rate', 0.1)
            ->assertJsonPath('workspace.capabilities.partner_bank_rails', 'awaiting_partner_approval');
    }

    public function test_student_can_create_a_campaign_and_the_goal_is_grossed_up(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/campaigns', [
            'title' => 'Clinical year levy',
            'story' => 'Lab coats and stethoscopes for the clinical year.',
            'net_base_goal_minor' => 100000,
            'overfunding_policy' => 'continue_accepting',
        ]);

        $response->assertCreated()
            ->assertJsonPath('campaign.net_base_goal_minor', 100000)
            ->assertJsonPath('campaign.gross_target_minor', 111112)
            ->assertJsonPath('campaign.status', 'submitted');

        $campaign = Campaign::firstOrFail();
        $this->assertSame(11112, $campaign->gross_target_minor - $campaign->net_base_goal_minor);
    }

    public function test_donation_credits_the_campaign_and_the_students_ledger(): void
    {
        $campaign = Campaign::create([
            'slug' => 'donation-target',
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'title' => 'Donation target',
            'story' => 'Story',
            'student_name' => $this->student->name,
            'net_base_goal_minor' => 90000,
            'gross_target_minor' => 100000,
            'status' => 'active',
        ]);

        $donor = User::factory()->create(['role' => 'donor']);
        Sanctum::actingAs($donor);

        $this->postJson("/api/campaigns/{$campaign->slug}/pledge", [
            'amount_minor' => 50000,
            'donor_name' => 'Kind Donor',
        ])->assertCreated()
            ->assertJsonPath('donation.fee_minor', 5000)
            ->assertJsonPath('donation.net_minor', 45000);

        $campaign->refresh();

        $this->assertSame(50000, $campaign->gross_raised_minor);
        $this->assertSame(5000, $campaign->fee_collected_minor);
        $this->assertSame(1, $campaign->donor_count);

        $this->assertDatabaseHas('ledger_entries', [
            'user_id' => $this->student->id,
            'entry_type' => 'credit',
            'amount_minor' => 45000,
            'source_type' => 'campaign_donation',
        ]);
    }

    public function test_savings_goal_cannot_be_funded_beyond_the_wallet_balance(): void
    {
        Sanctum::actingAs($this->student);

        $goalId = $this->postJson('/api/savings-goals', [
            'title' => 'Next term fees',
            'target_amount_minor' => 300000,
            'category' => 'tuition',
        ])->assertCreated()->json('savings_goal.id');

        $this->postJson("/api/savings-goals/{$goalId}/fund", ['amount_minor' => 100000])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Your wallet does not have enough available balance for this contribution.',
            ]);
    }

    public function test_notifications_can_be_listed_and_marked_read(): void
    {
        AppNotification::create([
            'user_id' => $this->student->id,
            'type' => 'payment_success',
            'title' => 'Payment received',
            'body' => 'Your payment was received.',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('notifications.0.title', 'Payment received');

        $this->postJson('/api/notifications/mark-all-read')->assertOk();

        $this->assertSame(
            0,
            AppNotification::where('user_id', $this->student->id)->whereNull('read_at')->count()
        );
    }

    public function test_ngo_surfaces_are_scoped_to_ngo_accounts(): void
    {
        WorkspaceRecord::create([
            'type' => 'ngo_team',
            'owner_id' => $this->ngo->id,
            'key' => 'ngo_staff_test',
            'data' => ['id' => 'ngo_staff_test', 'name' => 'Programme Lead', 'role' => 'program_manager'],
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/ngo/team')->assertForbidden();

        Sanctum::actingAs($this->ngo);
        $this->getJson('/api/ngo/team')
            ->assertOk()
            ->assertJsonPath('team.0.name', 'Programme Lead');
    }

    public function test_only_administrators_can_change_the_platform_fee(): void
    {
        Sanctum::actingAs($this->schoolAdmin);
        $this->putJson('/api/admin/fee-config', ['percentage_rate' => 0.05])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/admin/fee-config', ['percentage_rate' => 0.08])
            ->assertOk()
            ->assertJsonPath('fee_config.percentage_rate', 0.08);
    }

    public function test_school_admin_workspace_reports_a_real_roster(): void
    {
        Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-ROSTER-1',
            'amount_minor' => 200000,
            'status' => 'issued',
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson('/api/workspace')
            ->assertOk()
            ->assertJsonPath('workspace.school_admin.students.0.id', $this->student->id)
            ->assertJsonPath('workspace.school_admin.metrics.total_billed_minor', 200000)
            ->assertJsonPath('workspace.school_admin.metrics.outstanding_minor', 200000)
            ->assertJsonPath('workspace.school_admin.metrics.student_count', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $otherSchool;

    private User $student;

    private User $classmate;

    private User $schoolAdmin;

    private User $otherAdmin;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Wallet School', 'slug' => 'wallet-school']);
        $this->otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school']);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->classmate = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);
        $this->otherAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->otherSchool->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(array $overrides = []): LedgerEntry
    {
        $key = 'test-'.uniqid('', true);

        return LedgerEntry::create(array_merge([
            'school_id' => $this->school->id,
            'user_id' => $this->student->id,
            'entry_type' => 'credit',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'source_type' => 'adjustment',
            'source_id' => $key,
            'idempotency_key' => $key,
            'occurred_at' => now(),
        ], $overrides));
    }

    public function test_wallet_derives_balance_from_credits_and_debits(): void
    {
        $this->entry(['amount_minor' => 100000]);
        $this->entry(['amount_minor' => 50000]);
        $this->entry(['entry_type' => 'debit', 'amount_minor' => 30000]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_minor', 120000)
            ->assertJsonPath('wallet.totals.credits_minor', 150000)
            ->assertJsonPath('wallet.totals.debits_minor', 30000)
            ->assertJsonPath('wallet.entry_count', 3);
    }

    public function test_holds_reduce_available_balance_and_releases_restore_it(): void
    {
        $this->entry(['amount_minor' => 100000]);
        $this->entry(['entry_type' => 'hold', 'amount_minor' => 40000]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 60000);

        $this->entry(['entry_type' => 'release', 'amount_minor' => 40000]);

        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 100000);
    }

    public function test_refunds_reduce_the_balance(): void
    {
        $this->entry(['amount_minor' => 100000]);
        $this->entry(['entry_type' => 'refund', 'amount_minor' => 20000]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet')
            ->assertJsonPath('wallet.available_minor', 80000)
            ->assertJsonPath('wallet.totals.refunds_minor', 20000);
    }

    public function test_other_currencies_are_excluded(): void
    {
        $this->entry(['amount_minor' => 100000]);
        $this->entry(['amount_minor' => 50000, 'currency' => 'USD']);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet')
            ->assertJsonPath('wallet.available_minor', 100000)
            ->assertJsonPath('wallet.entry_count', 1);

        $this->getJson('/api/wallet?currency=USD')
            ->assertJsonPath('wallet.available_minor', 50000);
    }

    public function test_wallet_starts_at_zero(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_minor', 0)
            ->assertJsonPath('wallet.entry_count', 0);
    }

    public function test_rejects_a_malformed_currency(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/wallet?currency=NAIRA')->assertStatus(422);
    }

    public function test_student_ledger_only_shows_their_own_entries(): void
    {
        $mine = $this->entry(['amount_minor' => 111]);
        $theirs = $this->entry(['user_id' => $this->classmate->id, 'amount_minor' => 222]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/ledger')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame($mine->id, $response->json('data.0.id'));
        $this->assertNotSame($theirs->id, $response->json('data.0.id'));
    }

    public function test_school_admin_sees_every_entry_for_their_school(): void
    {
        $this->entry(['amount_minor' => 111]);
        $this->entry(['user_id' => $this->classmate->id, 'amount_minor' => 222]);
        $this->entry([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherAdmin->id,
            'amount_minor' => 333,
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson('/api/ledger')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_sees_all_entries(): void
    {
        $this->entry();
        $this->entry([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherAdmin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/ledger')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_ledger_can_be_filtered_by_entry_type(): void
    {
        $this->entry(['entry_type' => 'credit']);
        $this->entry(['entry_type' => 'hold']);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/ledger?entry_type=hold')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entry_type', 'hold');
    }

    public function test_school_wallet_sums_entries_across_its_students(): void
    {
        $this->entry(['amount_minor' => 100000]);
        $this->entry(['user_id' => $this->classmate->id, 'amount_minor' => 250000]);
        $this->entry([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherAdmin->id,
            'amount_minor' => 999999,
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson("/api/schools/{$this->school->id}/wallet")
            ->assertOk()
            ->assertJsonPath('wallet.available_minor', 350000)
            ->assertJsonPath('wallet.entry_count', 2);
    }

    public function test_school_admin_cannot_view_another_schools_wallet(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson("/api/schools/{$this->otherSchool->id}/wallet")->assertForbidden();
    }

    public function test_student_cannot_view_a_school_wallet(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson("/api/schools/{$this->school->id}/wallet")->assertForbidden();
    }

    public function test_admin_can_view_any_school_wallet(): void
    {
        $this->entry(['amount_minor' => 42000]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/schools/{$this->school->id}/wallet")
            ->assertOk()
            ->assertJsonPath('wallet.available_minor', 42000);
    }
}

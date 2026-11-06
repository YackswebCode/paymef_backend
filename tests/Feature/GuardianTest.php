<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuardianTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $otherSchool;

    private User $student;

    private User $otherStudent;

    private User $parent;

    private User $schoolAdmin;

    private User $otherAdmin;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paymef.paystack.secret_key' => 'sk_test_fake',
            'paymef.paystack.public_key' => 'pk_test_fake',
            'paymef.paystack.base_url' => 'https://api.paystack.test',
        ]);

        $this->school = School::create([
            'name' => 'Guardian School',
            'slug' => 'guardian-school',
            'verification_status' => 'verified',
        ]);
        $this->otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school']);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->otherStudent = User::factory()->create(['role' => 'student', 'school_id' => $this->otherSchool->id]);
        $this->parent = User::factory()->create(['role' => 'parent']);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);
        $this->otherAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->otherSchool->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-GRD-001',
            'amount_minor' => 150000,
            'currency' => 'NGN',
        ]);
    }

    private function link(?User $parent = null, ?User $student = null): Guardian
    {
        $parent ??= $this->parent;
        $student ??= $this->student;

        return Guardian::create([
            'school_id' => $student->school_id,
            'parent_id' => $parent->id,
            'student_id' => $student->id,
            'relationship' => 'mother',
        ]);
    }

    public function test_school_admin_links_a_parent_to_a_student(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/students/{$this->student->id}/guardians", [
            'email' => $this->parent->email,
            'relationship' => 'mother',
        ])
            ->assertCreated()
            ->assertJsonPath('guardian.parent.id', $this->parent->id)
            ->assertJsonPath('guardian.relationship', 'mother');

        $this->assertDatabaseHas('guardians', [
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
        ]);
    }

    public function test_linking_the_same_parent_twice_is_idempotent(): void
    {
        $this->link();

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/students/{$this->student->id}/guardians", [
            'email' => $this->parent->email,
            'relationship' => 'mother',
        ])->assertOk();

        $this->assertDatabaseCount('guardians', 1);
    }

    public function test_only_parent_accounts_can_be_linked(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/students/{$this->student->id}/guardians", [
            'email' => $this->otherStudent->email,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('guardians', 0);
    }

    public function test_guardian_management_is_scoped_to_the_school(): void
    {
        Sanctum::actingAs($this->otherAdmin);

        $this->postJson("/api/students/{$this->student->id}/guardians", [
            'email' => $this->parent->email,
        ])->assertForbidden();

        $this->getJson("/api/students/{$this->student->id}/guardians")->assertForbidden();
    }

    public function test_administrator_can_link_guardians_for_any_school(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/students/{$this->otherStudent->id}/guardians", [
            'email' => $this->parent->email,
            'relationship' => 'father',
        ])
            ->assertCreated()
            ->assertJsonPath('guardian.school_id', $this->otherSchool->id);
    }

    public function test_school_admin_lists_the_guardians_of_a_student(): void
    {
        $this->link();

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson("/api/students/{$this->student->id}/guardians")
            ->assertOk()
            ->assertJsonCount(1, 'guardians')
            ->assertJsonPath('guardians.0.parent.email', $this->parent->email);
    }

    public function test_parent_only_sees_invoices_for_their_children(): void
    {
        $this->link();

        Invoice::create([
            'school_id' => $this->otherSchool->id,
            'student_id' => $this->otherStudent->id,
            'reference' => 'INV-OTHER-001',
            'amount_minor' => 90000,
            'currency' => 'NGN',
        ]);

        Sanctum::actingAs($this->parent);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_parent_can_view_and_pay_a_childs_invoice(): void
    {
        $this->link();

        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'PMF-PARENT-1',
                    'authorization_url' => 'https://checkout.paystack.com/parent1',
                ],
            ]),
        ]);

        Sanctum::actingAs($this->parent);

        $this->getJson("/api/invoices/{$this->invoice->id}")->assertOk();

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertCreated()
            ->assertJsonPath('payment.amount_minor', 150000);

        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $this->invoice->id,
            'payer_id' => $this->parent->id,
            'status' => 'pending',
        ]);
    }

    public function test_parent_cannot_view_an_unrelated_invoice(): void
    {
        $this->link(parent: $this->parent, student: $this->otherStudent);

        Sanctum::actingAs($this->parent);

        $this->getJson("/api/invoices/{$this->invoice->id}")->assertForbidden();
        $this->postJson("/api/invoices/{$this->invoice->id}/pay")->assertForbidden();
    }

    public function test_removing_a_link_revokes_access(): void
    {
        $this->link();

        Sanctum::actingAs($this->schoolAdmin);

        $this->deleteJson("/api/students/{$this->student->id}/guardians/{$this->parent->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('guardians', 0);

        Sanctum::actingAs($this->parent);

        $this->getJson("/api/invoices/{$this->invoice->id}")->assertForbidden();
    }

    public function test_parent_lists_children_with_outstanding_balance(): void
    {
        $this->link();

        Sanctum::actingAs($this->parent);

        $this->getJson('/api/children')
            ->assertOk()
            ->assertJsonCount(1, 'children')
            ->assertJsonPath('children.0.student.id', $this->student->id)
            ->assertJsonPath('children.0.relationship', 'mother')
            ->assertJsonPath('children.0.outstanding_minor', 150000);
    }

    public function test_outstanding_balance_accounts_for_partial_payments(): void
    {
        $this->link();

        PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'payer_id' => $this->parent->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-PARTIAL-1',
            'amount_minor' => 50000,
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        $this->invoice->update(['status' => 'partially_paid']);

        Sanctum::actingAs($this->parent);

        $this->getJson('/api/children')
            ->assertOk()
            ->assertJsonPath('children.0.outstanding_minor', 100000);
    }

    public function test_childless_accounts_cannot_list_children(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/children')->assertForbidden();
    }

    public function test_guardian_payment_credits_the_students_wallet(): void
    {
        $this->link();

        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'PMF-PARENT-2',
                    'authorization_url' => 'https://checkout.paystack.com/parent2',
                ],
            ]),
        ]);

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")->assertCreated();

        $attempt = PaymentAttempt::firstOrFail();

        app(PaymentService::class)->settle($attempt, [
            'status' => 'success',
            'amount' => 150000,
            'currency' => 'NGN',
            'reference' => $attempt->provider_reference,
        ]);

        $entry = LedgerEntry::firstOrFail();

        $this->assertSame($this->student->id, $entry->user_id);
        $this->assertSame('credit', $entry->entry_type);
        $this->assertSame(150000, $entry->amount_minor);
        $this->assertSame($this->parent->id, $entry->meta['payer_id']);
        $this->assertSame('paid', $this->invoice->fresh()->status);

        // The money sits in the student's wallet, not the guardian's.
        Sanctum::actingAs($this->student);
        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 150000);

        Sanctum::actingAs($this->parent);
        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $otherSchool;

    private User $student;

    private User $classmate;

    private User $schoolAdmin;

    private User $otherAdmin;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Manage School', 'slug' => 'manage-school']);
        $this->otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school']);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->classmate = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);
        $this->otherAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->otherSchool->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-MANAGE-001',
            'amount_minor' => 150000,
            'currency' => 'NGN',
        ]);
    }

    private function successfulPayment(): void
    {
        PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-MANAGE-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        $this->invoice->update(['status' => 'paid']);
    }

    public function test_school_admin_updates_description_and_due_date(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->patchJson("/api/invoices/{$this->invoice->id}", [
            'description' => 'Second term fees',
            'due_date' => '2026-12-15',
        ])
            ->assertOk()
            ->assertJsonPath('invoice.description', 'Second term fees');

        $this->assertSame('Second term fees', $this->invoice->fresh()->description);
    }

    public function test_students_cannot_edit_invoices(): void
    {
        Sanctum::actingAs($this->student);

        $this->patchJson("/api/invoices/{$this->invoice->id}", ['description' => 'nope'])
            ->assertForbidden();
    }

    public function test_the_amount_is_locked_once_money_has_arrived(): void
    {
        $this->successfulPayment();

        Sanctum::actingAs($this->schoolAdmin);

        $this->patchJson("/api/invoices/{$this->invoice->id}", ['amount_minor' => 200000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_minor');

        $this->assertSame(150000, $this->invoice->fresh()->amount_minor);
    }

    public function test_an_unpaid_invoice_can_be_cancelled(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/invoices/{$this->invoice->id}/cancel")
            ->assertOk()
            ->assertJsonPath('invoice.status', 'cancelled');

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');
    }

    public function test_a_paid_invoice_cannot_be_cancelled(): void
    {
        $this->successfulPayment();

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/invoices/{$this->invoice->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_only_a_draft_invoice_can_be_issued(): void
    {
        $this->invoice->update(['status' => 'draft']);

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/invoices/{$this->invoice->id}/issue")
            ->assertOk()
            ->assertJsonPath('invoice.status', 'issued');

        $this->postJson("/api/invoices/{$this->invoice->id}/issue")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_creates_a_batch_of_invoices(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoices/bulk', [
            'school_id' => $this->school->id,
            'invoices' => [
                ['student_id' => $this->student->id, 'amount_minor' => 25000, 'description' => 'Bus'],
                ['student_id' => $this->classmate->id, 'amount_minor' => 35000, 'description' => 'Books'],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'invoices');

        $this->assertDatabaseCount('invoices', 3);
        $this->assertDatabaseHas('invoices', ['student_id' => $this->classmate->id, 'amount_minor' => 35000]);
    }

    public function test_bulk_creation_rejects_a_student_from_another_school(): void
    {
        $outsider = User::factory()->create(['role' => 'student', 'school_id' => $this->otherSchool->id]);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoices/bulk', [
            'school_id' => $this->school->id,
            'invoices' => [
                ['student_id' => $this->student->id, 'amount_minor' => 25000],
                ['student_id' => $outsider->id, 'amount_minor' => 35000],
            ],
        ])->assertStatus(422);

        // The whole batch is rejected, so nothing is half-created.
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_school_admin_bulk_creation_stays_in_their_school(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson('/api/invoices/bulk', [
            'invoices' => [
                ['student_id' => $this->classmate->id, 'amount_minor' => 12000],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('invoices.0.school_id', $this->school->id);

        Sanctum::actingAs($this->otherAdmin);

        $this->postJson("/api/invoices/{$this->invoice->id}/cancel")->assertForbidden();
    }
}

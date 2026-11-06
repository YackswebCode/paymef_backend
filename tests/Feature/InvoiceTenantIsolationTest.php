<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private User $admin;

    private User $adminA;

    private User $adminB;

    private User $studentA;

    private User $studentB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolA = School::create([
            'name' => 'School A',
            'slug' => 'school-a',
            'verification_status' => 'verified',
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'slug' => 'school-b',
            'verification_status' => 'verified',
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminA = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->schoolA->id]);
        $this->adminB = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->schoolB->id]);
        $this->studentA = User::factory()->create(['role' => 'student', 'school_id' => $this->schoolA->id]);
        $this->studentB = User::factory()->create(['role' => 'student', 'school_id' => $this->schoolB->id]);

        $this->invoiceA = Invoice::create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'reference' => 'INV-A-001',
            'amount_minor' => 5000000,
        ]);

        $this->invoiceB = Invoice::create([
            'school_id' => $this->schoolB->id,
            'student_id' => $this->studentB->id,
            'reference' => 'INV-B-001',
            'amount_minor' => 7500000,
        ]);
    }

    public function test_school_admin_only_lists_their_own_school_invoices(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->invoiceA->id)
            ->assertJsonPath('data.0.school_id', $this->schoolA->id);
    }

    public function test_school_admin_cannot_view_another_schools_invoice(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/invoices/{$this->invoiceB->id}")->assertForbidden();
    }

    public function test_school_admin_can_view_their_own_school_invoice(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/invoices/{$this->invoiceA->id}")
            ->assertOk()
            ->assertJsonPath('invoice.reference', 'INV-A-001')
            ->assertJsonPath('invoice.school.name', 'School A');
    }

    public function test_student_only_sees_their_own_invoices(): void
    {
        Sanctum::actingAs($this->studentA);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->invoiceA->id);

        $this->getJson("/api/invoices/{$this->invoiceB->id}")->assertForbidden();
    }

    public function test_students_and_parents_cannot_create_invoices(): void
    {
        foreach ([$this->studentA, User::factory()->create(['role' => 'parent'])] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/invoices', [
                'student_id' => $this->studentA->id,
                'amount_minor' => 100000,
            ])->assertForbidden();
        }
    }

    public function test_school_admin_creates_an_invoice_for_their_own_student(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 150000,
            'due_date' => now()->addWeek()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('invoice.school_id', $this->schoolA->id)
            ->assertJsonPath('invoice.student_id', $this->studentA->id)
            ->assertJsonPath('invoice.status', 'issued');

        $this->assertDatabaseHas('invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 150000,
        ]);
    }

    public function test_generated_reference_is_used_when_none_is_supplied(): void
    {
        Sanctum::actingAs($this->adminA);

        $reference = $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 90000,
        ])->assertCreated()->json('invoice.reference');

        $this->assertStringStartsWith('INV-', $reference);
    }

    public function test_school_admin_cannot_invoice_a_student_from_another_school(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentB->id,
            'amount_minor' => 150000,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_school_admin_cannot_override_the_school_id(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/invoices', [
            'school_id' => $this->schoolB->id,
            'student_id' => $this->studentA->id,
            'amount_minor' => 150000,
        ])->assertForbidden();

        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_school_admin_without_a_school_cannot_create_invoices(): void
    {
        $orphan = User::factory()->create(['role' => 'school_admin', 'school_id' => null]);

        Sanctum::actingAs($orphan);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 150000,
        ])->assertForbidden();
    }

    public function test_admin_can_view_and_create_for_any_school(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/invoices/{$this->invoiceB->id}")->assertOk();
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/invoices', [
            'school_id' => $this->schoolB->id,
            'student_id' => $this->studentB->id,
            'amount_minor' => 250000,
        ])->assertCreated()
            ->assertJsonPath('invoice.school_id', $this->schoolB->id);
    }

    public function test_admin_must_state_the_school_when_creating(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 250000,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('school_id');
    }

    public function test_unverified_school_admin_cannot_list_invoices(): void
    {
        $unverified = User::factory()->unverified()->create([
            'role' => 'school_admin',
            'school_id' => $this->schoolA->id,
        ]);

        Sanctum::actingAs($unverified);

        $this->getJson('/api/invoices')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_invoice_reference_must_be_unique(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 30000,
            'reference' => 'INV-A-001',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('reference');
    }

    public function test_invoice_creation_requires_a_positive_integer_amount(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson('/api/invoices', [
            'student_id' => $this->studentA->id,
            'amount_minor' => 10.5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('amount_minor');
    }
}

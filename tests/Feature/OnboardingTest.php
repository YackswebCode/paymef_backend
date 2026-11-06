<?php

namespace Tests\Feature;

use App\Mail\AccountInviteMail;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $schoolAdmin;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Onboarding School',
            'slug' => 'onboarding-school',
            'verification_status' => 'verified',
        ]);

        $this->schoolAdmin = User::factory()->create([
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->student = User::factory()->create([
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);
    }

    public function test_admin_creates_a_school(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/schools', ['name' => 'Bright Future Academy'])
            ->assertCreated()
            ->assertJsonPath('school.slug', 'bright-future-academy')
            ->assertJsonPath('school.verification_status', 'pending');

        $this->assertDatabaseHas('schools', ['slug' => 'bright-future-academy']);
    }

    public function test_only_administrators_create_and_verify_schools(): void
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson('/api/schools', ['name' => 'Nope Academy'])->assertForbidden();
        $this->patchJson("/api/schools/{$this->school->id}", ['verification_status' => 'rejected'])
            ->assertForbidden();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/schools/{$this->school->id}", ['verification_status' => 'verified'])
            ->assertOk()
            ->assertJsonPath('school.verification_status', 'verified');
    }

    public function test_admin_invites_a_school_administrator(): void
    {
        Mail::fake();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/schools/{$this->school->id}/admins", [
            'name' => 'New Admin',
            'email' => 'NewAdmin@Example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'school_admin')
            ->assertJsonPath('user.school_id', $this->school->id)
            ->assertJsonPath('invite_sent', true);

        $invited = User::where('email', 'newadmin@example.com')->firstOrFail();

        $this->assertNotNull($invited->email_verified_at);
        Mail::assertQueued(AccountInviteMail::class, 1);
    }

    public function test_an_existing_email_cannot_be_invited_again(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/schools/{$this->school->id}/admins", [
            'name' => 'Duplicate',
            'email' => $this->student->email,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_school_admin_creates_a_student_in_their_school(): void
    {
        Mail::fake();

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson('/api/students', [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('student.role', 'student')
            ->assertJsonPath('student.school_id', $this->school->id);

        Mail::assertQueued(AccountInviteMail::class, 1);
    }

    public function test_school_admin_cannot_create_students_for_another_school(): void
    {
        $other = School::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson('/api/students', [
            'school_id' => $other->id,
            'name' => 'Moved',
            'email' => 'moved@example.com',
        ])->assertForbidden();
    }

    public function test_bulk_student_import_skips_existing_accounts(): void
    {
        Mail::fake();

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson('/api/students/bulk', [
            'students' => [
                ['name' => 'One', 'email' => 'one@example.com'],
                ['name' => 'Two', 'email' => 'two@example.com'],
                ['name' => 'Existing', 'email' => $this->student->email],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'created')
            ->assertJsonCount(1, 'skipped');

        Mail::assertQueued(AccountInviteMail::class, 2);
    }

    public function test_student_list_is_school_scoped(): void
    {
        $other = School::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        User::factory()->create(['role' => 'student', 'school_id' => $other->id]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/students?school_id={$other->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_school_summary_reports_money_and_counts(): void
    {
        Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-SUM-001',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'partially_paid',
        ]);

        PaymentAttempt::create([
            'invoice_id' => Invoice::firstOrFail()->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-SUM-1',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->getJson("/api/schools/{$this->school->id}/summary")
            ->assertOk()
            ->assertJsonPath('summary.invoiced_minor', 150000)
            ->assertJsonPath('summary.collected_minor', 100000)
            ->assertJsonPath('summary.outstanding_minor', 50000)
            ->assertJsonPath('summary.student_count', 1);
    }

    public function test_profile_can_be_updated(): void
    {
        Sanctum::actingAs($this->student);

        $this->patchJson('/api/auth/profile', ['name' => 'Updated Name', 'phone' => '08012345678'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Name');
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->postJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123!', $this->student->fresh()->password));
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $this->student->createToken('phone');
        $this->student->createToken('laptop');

        $this->assertSame(2, $this->student->tokens()->count());

        Sanctum::actingAs($this->student);

        $this->postJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertSame(0, $this->student->tokens()->count());
    }
}

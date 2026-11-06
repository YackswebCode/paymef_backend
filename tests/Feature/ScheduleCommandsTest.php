<?php

namespace Tests\Feature;

use App\Mail\InvoiceReminderMail;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduleCommandsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paymef.paystack.secret_key' => 'sk_test_fake',
            'paymef.paystack.public_key' => 'pk_test_fake',
            'paymef.paystack.base_url' => 'https://api.paystack.test',
        ]);

        $this->school = School::create([
            'name' => 'Schedule School',
            'slug' => 'schedule-school',
            'verification_status' => 'verified',
        ]);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->parent = User::factory()->create(['role' => 'parent']);
    }

    private function invoice(array $overrides = []): Invoice
    {
        static $sequence = 0;
        $sequence++;

        return Invoice::create(array_merge([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-SCH-'.$sequence,
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'issued',
        ], $overrides));
    }

    public function test_unpaid_invoices_past_due_become_overdue(): void
    {
        $late = $this->invoice(['due_date' => now()->subDay()->toDateString()]);
        $future = $this->invoice(['due_date' => now()->addWeek()->toDateString()]);
        $paid = $this->invoice(['due_date' => now()->subDay()->toDateString(), 'status' => 'paid']);

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $late->fresh()->status);
        $this->assertSame('issued', $future->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_pending_payments_are_settled_when_the_webhook_was_lost(): void
    {
        $invoice = $this->invoice();

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-STALE-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);

        $attempt->forceFill(['created_at' => now()->subHour()])->save();

        Http::fake([
            'api.paystack.test/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 150000, 'currency' => 'NGN'],
            ]),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('success', $attempt->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('ledger_entries', [
            'idempotency_key' => 'paystack:PMF-STALE-1',
            'amount_minor' => 150000,
        ]);
    }

    public function test_reconciliation_skips_recent_attempts_and_marks_abandoned_ones(): void
    {
        $invoice = $this->invoice();

        $recent = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-RECENT-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);

        $stale = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-OLD-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);
        $stale->forceFill(['created_at' => now()->subHour()])->save();

        Http::fake([
            'api.paystack.test/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'abandoned', 'amount' => 150000, 'currency' => 'NGN'],
            ]),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $recent->fresh()->status);
        $this->assertSame('abandoned', $stale->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_reminders_go_to_student_and_guardian_once(): void
    {
        Guardian::create([
            'school_id' => $this->school->id,
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'relationship' => 'father',
        ]);

        $invoice = $this->invoice(['due_date' => now()->addDays(2)->toDateString()]);

        Mail::fake();

        $this->artisan('invoices:send-reminders')->assertSuccessful();

        Mail::assertQueued(InvoiceReminderMail::class, 2);
        $this->assertNotNull($invoice->fresh()->reminded_at);

        // A second run inside the cooldown window sends nothing new.
        $this->artisan('invoices:send-reminders')->assertSuccessful();
        Mail::assertQueued(InvoiceReminderMail::class, 2);
    }

    public function test_reminders_skip_invoices_that_are_not_due_soon(): void
    {
        $this->invoice(['due_date' => now()->addMonths(2)->toDateString()]);

        Mail::fake();

        $this->artisan('invoices:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }
}

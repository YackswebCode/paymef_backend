<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $student;

    private User $otherStudent;

    private User $schoolAdmin;

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
            'name' => 'Pay School',
            'slug' => 'pay-school',
            'verification_status' => 'verified',
        ]);

        $otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school']);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->otherStudent = User::factory()->create(['role' => 'student', 'school_id' => $otherSchool->id]);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-PAY-001',
            'amount_minor' => 150000,
            'currency' => 'NGN',
        ]);
    }

    private function fakeInitialize(): void
    {
        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'PMF-TEST-1',
                    'authorization_url' => 'https://checkout.paystack.com/test123',
                    'access_code' => 'test123',
                ],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeVerify(array $data): void
    {
        Http::fake([
            'api.paystack.test/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $data,
            ]),
        ]);
    }

    private function pendingAttempt(string $reference = 'PMF-WEBHOOK-1'): PaymentAttempt
    {
        return PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload, ?string $signature = null)
    {
        $body = (string) json_encode($payload);

        $signature ??= hash_hmac('sha512', $body, (string) config('paymef.paystack.secret_key'));

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_student_can_initialize_payment_for_their_invoice(): void
    {
        $this->fakeInitialize();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertCreated()
            ->assertJsonPath('payment.authorization_url', 'https://checkout.paystack.com/test123')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount_minor', 150000);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && $request['amount'] === 150000
                && $request['currency'] === 'NGN'
                && $request['email'] === $this->student->email
                && $request['metadata']['invoice_id'] === $this->invoice->id;
        });

        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $this->invoice->id,
            'status' => 'pending',
            'amount_minor' => 150000,
        ]);
    }

    public function test_other_users_cannot_pay_the_invoice(): void
    {
        $this->fakeInitialize();

        Sanctum::actingAs($this->otherStudent);
        $this->postJson("/api/invoices/{$this->invoice->id}/pay")->assertForbidden();

        Sanctum::actingAs($this->schoolAdmin);
        $this->postJson("/api/invoices/{$this->invoice->id}/pay")->assertCreated();

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_unverified_student_cannot_start_a_payment(): void
    {
        $this->fakeInitialize();

        $unverified = User::factory()->unverified()->create([
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        Sanctum::actingAs($unverified);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_provider_rejection_is_returned_as_a_validation_error(): void
    {
        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response([
                'status' => false,
                'message' => '"email" must be a valid email',
            ], 400),
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $this->invoice->id,
            'status' => 'failed',
        ]);
    }

    public function test_paid_invoice_cannot_be_paid_again(): void
    {
        $this->fakeInitialize();
        $this->invoice->update(['status' => 'paid']);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');
    }

    public function test_webhook_credits_the_ledger_once_and_marks_invoice_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeVerify([
            'status' => 'success',
            'amount' => 150000,
            'currency' => 'NGN',
            'reference' => 'PMF-WEBHOOK-1',
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'PMF-WEBHOOK-1']];

        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame('success', $attempt->fresh()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertDatabaseHas('ledger_entries', [
            'idempotency_key' => 'paystack:PMF-WEBHOOK-1',
            'entry_type' => 'credit',
            'amount_minor' => 150000,
            'school_id' => $this->school->id,
            'user_id' => $this->student->id,
        ]);
    }

    public function test_replayed_webhook_does_not_post_the_ledger_twice(): void
    {
        $this->pendingAttempt();

        $this->fakeVerify([
            'status' => 'success',
            'amount' => 150000,
            'currency' => 'NGN',
            'reference' => 'PMF-WEBHOOK-1',
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'PMF-WEBHOOK-1']];

        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();

        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(150000, (int) LedgerEntry::sum('amount_minor'));
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $attempt = $this->pendingAttempt();
        Http::fake();

        $this->webhook(
            ['event' => 'charge.success', 'data' => ['reference' => 'PMF-WEBHOOK-1']],
            'not-a-real-signature'
        )->assertStatus(401);

        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('pending', $attempt->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_webhook_with_mismatched_amount_does_not_credit(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeVerify([
            'status' => 'success',
            'amount' => 1000,
            'currency' => 'NGN',
            'reference' => 'PMF-WEBHOOK-1',
        ]);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'PMF-WEBHOOK-1']])
            ->assertOk();

        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame('issued', $this->invoice->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_webhook_with_mismatched_currency_does_not_credit(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeVerify([
            'status' => 'success',
            'amount' => 150000,
            'currency' => 'USD',
            'reference' => 'PMF-WEBHOOK-1',
        ]);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'PMF-WEBHOOK-1']])
            ->assertOk();

        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_webhook_for_unknown_reference_is_ignored(): void
    {
        Http::fake();

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'UNKNOWN']])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_payment_status_endpoint_verifies_with_paystack(): void
    {
        $this->pendingAttempt('PMF-STATUS-1');

        $this->fakeVerify([
            'status' => 'success',
            'amount' => 150000,
            'currency' => 'NGN',
            'reference' => 'PMF-STATUS-1',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/payments/PMF-STATUS-1')
            ->assertOk()
            ->assertJsonPath('payment.status', 'success');

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_other_users_cannot_read_payment_status(): void
    {
        $this->pendingAttempt('PMF-STATUS-2');
        Sanctum::actingAs($this->otherStudent);

        $this->getJson('/api/payments/PMF-STATUS-2')->assertForbidden();
    }
}

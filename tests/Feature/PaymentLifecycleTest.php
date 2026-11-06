<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\School;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $otherSchool;

    private User $student;

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
            'name' => 'Lifecycle School',
            'slug' => 'lifecycle-school',
            'verification_status' => 'verified',
        ]);
        $this->otherSchool = School::create(['name' => 'Other School', 'slug' => 'other-school']);

        $this->student = User::factory()->create(['role' => 'student', 'school_id' => $this->school->id]);
        $this->parent = User::factory()->create(['role' => 'parent']);
        $this->schoolAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->school->id]);
        $this->otherAdmin = User::factory()->create(['role' => 'school_admin', 'school_id' => $this->otherSchool->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'reference' => 'INV-LIFE-001',
            'amount_minor' => 150000,
            'currency' => 'NGN',
        ]);
    }

    private function fakeInitialize(string $reference = 'PMF-LIFE-1'): void
    {
        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'authorization_url' => 'https://checkout.paystack.com/'.$reference,
                ],
            ]),
        ]);
    }

    private function fakeVerify(string $reference, int $amount, string $status = 'success'): void
    {
        Http::fake([
            'api.paystack.test/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'reference' => $reference,
                ],
            ]),
        ]);
    }

    private function successfulPayment(int $amount = 150000, string $reference = 'PMF-PAID-1'): PaymentAttempt
    {
        $attempt = PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'payer_id' => $this->student->id,
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'amount_minor' => $amount,
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        LedgerEntry::create([
            'school_id' => $this->school->id,
            'user_id' => $this->student->id,
            'entry_type' => 'credit',
            'amount_minor' => $amount,
            'currency' => 'NGN',
            'source_type' => 'payment',
            'source_id' => (string) $attempt->id,
            'idempotency_key' => 'paystack:'.$reference,
            'occurred_at' => now(),
        ]);

        $this->invoice->update(['status' => 'paid']);

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhookRequest(array $payload)
    {
        $body = (string) json_encode($payload);
        $signature = hash_hmac('sha512', $body, (string) config('paymef.paystack.secret_key'));

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_installments_accumulate_until_the_invoice_is_paid(): void
    {
        // Two checkouts in one test: Http::fake merges stubs, so a sequence is
        // the only way to give the second initialize a different reference.
        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::sequence()
                ->push(['status' => true, 'data' => ['reference' => 'PMF-INST-1', 'authorization_url' => 'https://checkout.paystack.com/PMF-INST-1']])
                ->push(['status' => true, 'data' => ['reference' => 'PMF-INST-2', 'authorization_url' => 'https://checkout.paystack.com/PMF-INST-2']]),
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay", ['amount_minor' => 60000])
            ->assertCreated()
            ->assertJsonPath('payment.amount_minor', 60000);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/initialize')
            && $request['amount'] === 60000);

        $payments = app(PaymentService::class);
        $first = PaymentAttempt::where('provider_reference', 'PMF-INST-1')->firstOrFail();

        $payments->settle($first, [
            'status' => 'success', 'amount' => 60000, 'currency' => 'NGN', 'reference' => 'PMF-INST-1',
        ]);

        $this->assertSame('partially_paid', $this->invoice->fresh()->status);
        $this->assertSame(90000, $payments->outstandingFor($this->invoice->fresh()));

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertCreated()
            ->assertJsonPath('payment.amount_minor', 90000);

        $second = PaymentAttempt::where('provider_reference', 'PMF-INST-2')->firstOrFail();

        $payments->settle($second, [
            'status' => 'success', 'amount' => 90000, 'currency' => 'NGN', 'reference' => 'PMF-INST-2',
        ]);

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(0, $payments->outstandingFor($this->invoice->fresh()));
        $this->assertSame(150000, (int) LedgerEntry::where('user_id', $this->student->id)->sum('amount_minor'));
    }

    public function test_a_payment_cannot_exceed_the_outstanding_balance(): void
    {
        $this->fakeInitialize();

        Sanctum::actingAs($this->student);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay", ['amount_minor' => 200000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_minor');

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_the_same_idempotency_key_reuses_one_checkout(): void
    {
        $this->fakeInitialize('PMF-IDEM-1');

        Sanctum::actingAs($this->student);

        $first = $this->withHeader('Idempotency-Key', 'checkout-abc')
            ->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertCreated()
            ->json('payment.reference');

        $second = $this->withHeader('Idempotency-Key', 'checkout-abc')
            ->postJson("/api/invoices/{$this->invoice->id}/pay")
            ->assertCreated()
            ->json('payment.reference');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public function test_payment_history_is_scoped_to_what_the_user_can_see(): void
    {
        PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-HIST-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);

        $foreignInvoice = Invoice::create([
            'school_id' => $this->otherSchool->id,
            'student_id' => $this->otherAdmin->id,
            'reference' => 'INV-OTHER-HIST',
            'amount_minor' => 50000,
            'currency' => 'NGN',
        ]);

        PaymentAttempt::create([
            'invoice_id' => $foreignInvoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-HIST-2',
            'amount_minor' => 50000,
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/payments')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->schoolAdmin);
        $this->getJson('/api/payments')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/payments?status=success')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_successful_payment_queues_a_receipt_for_payer_and_student(): void
    {
        Guardian::create([
            'school_id' => $this->school->id,
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'relationship' => 'mother',
        ]);

        Mail::fake();
        $this->fakeInitialize('PMF-RCPT-1');

        Sanctum::actingAs($this->parent);

        $this->postJson("/api/invoices/{$this->invoice->id}/pay")->assertCreated();

        $this->fakeVerify('PMF-RCPT-1', 150000);

        $this->getJson('/api/payments/PMF-RCPT-1')->assertOk()->assertJsonPath('payment.status', 'success');

        Mail::assertQueued(PaymentReceiptMail::class, 2);
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_school_admin_can_refund_a_payment_and_the_ledger_reverses(): void
    {
        $attempt = $this->successfulPayment();

        Http::fake([
            'api.paystack.test/refund' => Http::response([
                'status' => true,
                'data' => ['id' => 9001, 'status' => 'processed', 'amount' => 150000, 'currency' => 'NGN'],
            ]),
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/payments/{$attempt->provider_reference}/refund", ['reason' => 'Term cancelled'])
            ->assertCreated()
            ->assertJsonPath('refund.status', 'processed')
            ->assertJsonPath('refund.amount_minor', 150000);

        $this->assertDatabaseHas('refunds', [
            'payment_attempt_id' => $attempt->id,
            'status' => 'processed',
            'amount_minor' => 150000,
        ]);

        $this->assertDatabaseHas('ledger_entries', [
            'entry_type' => 'refund',
            'amount_minor' => 150000,
            'user_id' => $this->student->id,
        ]);

        $this->assertSame('issued', $this->invoice->fresh()->status);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 0);

        // A fully refunded payment cannot be refunded again.
        Sanctum::actingAs($this->schoolAdmin);
        $this->postJson("/api/payments/{$attempt->provider_reference}/refund")
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment');
    }

    public function test_partial_refund_leaves_the_rest_owed(): void
    {
        $attempt = $this->successfulPayment();

        Http::fake([
            'api.paystack.test/refund' => Http::response([
                'status' => true,
                'data' => ['id' => 9002, 'status' => 'processed', 'amount' => 50000, 'currency' => 'NGN'],
            ]),
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/payments/{$attempt->provider_reference}/refund", ['amount_minor' => 50000])
            ->assertCreated()
            ->assertJsonPath('refund.amount_minor', 50000);

        $this->assertSame('partially_paid', $this->invoice->fresh()->status);
        $this->assertSame(50000, app(PaymentService::class)->outstandingFor($this->invoice->fresh()));

        Sanctum::actingAs($this->student);
        $this->getJson('/api/wallet')->assertJsonPath('wallet.available_minor', 100000);
    }

    public function test_students_and_other_schools_cannot_refund(): void
    {
        $attempt = $this->successfulPayment();
        Http::fake();

        Sanctum::actingAs($this->student);
        $this->postJson("/api/payments/{$attempt->provider_reference}/refund")->assertForbidden();

        Sanctum::actingAs($this->otherAdmin);
        $this->postJson("/api/payments/{$attempt->provider_reference}/refund")->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_refund_webhook_marks_a_pending_refund_processed(): void
    {
        $attempt = $this->successfulPayment();

        Http::fake([
            'api.paystack.test/refund' => Http::response([
                'status' => true,
                'data' => ['id' => 9003, 'status' => 'pending', 'amount' => 150000, 'currency' => 'NGN'],
            ]),
        ]);

        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/payments/{$attempt->provider_reference}/refund")
            ->assertCreated()
            ->assertJsonPath('refund.status', 'pending');

        $this->assertDatabaseCount('ledger_entries', 1);

        $this->webhookRequest([
            'event' => 'refund.processed',
            'data' => ['id' => 9003, 'status' => 'processed', 'amount' => 150000, 'currency' => 'NGN'],
        ])->assertOk()->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('refunds', ['provider_refund_id' => '9003', 'status' => 'processed']);
        $this->assertDatabaseHas('ledger_entries', ['entry_type' => 'refund', 'amount_minor' => 150000]);
        $this->assertSame('issued', $this->invoice->fresh()->status);
    }

    public function test_a_duplicate_webhook_is_recorded_once_and_not_reprocessed(): void
    {
        PaymentAttempt::create([
            'invoice_id' => $this->invoice->id,
            'provider' => 'paystack',
            'provider_reference' => 'PMF-DUP-1',
            'amount_minor' => 150000,
            'currency' => 'NGN',
            'status' => 'pending',
        ]);

        $this->fakeVerify('PMF-DUP-1', 150000);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'PMF-DUP-1']];

        $this->webhookRequest($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhookRequest($payload)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
}

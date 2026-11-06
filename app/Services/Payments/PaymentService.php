<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    /**
     * Invoice states in which a payment may be started.
     */
    private const PAYABLE_STATUSES = ['issued', 'partially_paid', 'overdue'];

    public function __construct(private readonly PaystackClient $paystack) {}

    public function initialize(Invoice $invoice, User $payer): PaymentAttempt
    {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice cannot be paid while it is '.$invoice->status.'.',
            ]);
        }

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'status' => 'initialized',
        ]);

        // Our own reference keeps the mapping explicit and unguessable.
        $reference = 'PMF-'.$attempt->id.'-'.Str::upper(Str::random(10));

        try {
            $data = $this->paystack->initializeTransaction([
                'email' => $payer->email,
                'amount' => (int) $invoice->amount_minor,
                'currency' => $invoice->currency,
                'reference' => $reference,
                'callback_url' => rtrim((string) config('paymef.frontend_url'), '/').'/payments/callback',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_reference' => $invoice->reference,
                    'school_id' => $invoice->school_id,
                    'payment_attempt_id' => $attempt->id,
                ],
            ]);
        } catch (PaystackException $e) {
            $attempt->update([
                'status' => 'failed',
                'provider_payload' => ['error' => $e->getMessage(), 'http_status' => $e->status()],
            ]);

            // Paystack rejected the request itself (bad email, invalid amount),
            // which is a client problem rather than a server failure.
            if ($e->status() >= 400 && $e->status() < 500) {
                throw ValidationException::withMessages(['payment' => $e->getMessage()]);
            }

            throw $e;
        } catch (Throwable $e) {
            $attempt->update([
                'status' => 'failed',
                'provider_payload' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        }

        $attempt->update([
            'provider_reference' => $data['reference'] ?? $reference,
            'status' => 'pending',
            'provider_payload' => $data,
        ]);

        return $attempt->refresh();
    }

    /**
     * Re-check a reference with Paystack and settle it. Returns null when the
     * reference does not belong to this application.
     */
    public function confirm(string $reference): ?PaymentAttempt
    {
        $attempt = PaymentAttempt::where('provider_reference', $reference)->first();

        if (! $attempt) {
            return null;
        }

        if ($attempt->status === 'success') {
            return $attempt;
        }

        return $this->settle($attempt, $this->paystack->verifyTransaction($reference));
    }

    /**
     * Apply a verified provider payload exactly once.
     *
     * @param  array<string, mixed>  $payload
     */
    public function settle(PaymentAttempt $attempt, array $payload): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $payload): PaymentAttempt {
            $locked = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'success') {
                return $locked;
            }

            $invoice = Invoice::query()
                ->whereKey($locked->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = (string) ($payload['status'] ?? '');

            if ($status !== 'success') {
                $locked->update([
                    'status' => $this->mapStatus($status),
                    'provider_payload' => $payload,
                ]);

                return $locked;
            }

            $paidAmount = (int) ($payload['amount'] ?? 0);
            $paidCurrency = strtoupper((string) ($payload['currency'] ?? ''));

            // Never credit a payment that does not match what was invoiced.
            if ($paidAmount !== (int) $locked->amount_minor
                || $paidCurrency !== strtoupper((string) $invoice->currency)) {
                $locked->update([
                    'status' => 'failed',
                    'provider_payload' => $payload,
                ]);

                return $locked;
            }

            $locked->update([
                'status' => 'success',
                'provider_payload' => $payload,
            ]);

            // The unique idempotency key is the database-level guard against
            // a replayed webhook posting the same money twice.
            LedgerEntry::firstOrCreate(
                ['idempotency_key' => 'paystack:'.$locked->provider_reference],
                [
                    'school_id' => $invoice->school_id,
                    'user_id' => $invoice->student_id,
                    'entry_type' => 'credit',
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $invoice->currency,
                    'source_type' => 'payment',
                    'source_id' => (string) $locked->id,
                    'meta' => [
                        'provider' => $locked->provider,
                        'provider_reference' => $locked->provider_reference,
                        'invoice_id' => $invoice->id,
                        'invoice_reference' => $invoice->reference,
                    ],
                    'occurred_at' => now(),
                ]
            );

            $this->syncInvoiceStatus($invoice);

            return $locked;
        });
    }

    private function syncInvoiceStatus(Invoice $invoice): void
    {
        $paid = (int) PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->sum('amount_minor');

        $status = match (true) {
            $paid >= (int) $invoice->amount_minor => 'paid',
            $paid > 0 => 'partially_paid',
            default => $invoice->status,
        };

        if ($status !== $invoice->status) {
            $invoice->update(['status' => $status]);
        }
    }

    private function mapStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'failed' => 'failed',
            'abandoned' => 'abandoned',
            default => 'pending',
        };
    }
}

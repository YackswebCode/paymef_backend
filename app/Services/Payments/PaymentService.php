<?php

namespace App\Services\Payments;

use App\Mail\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\User;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /**
     * Start a payment for the outstanding balance, or for a caller-specified
     * part of it so installments are possible.
     */
    public function initialize(
        Invoice $invoice,
        User $payer,
        ?int $amountMinor = null,
        ?string $idempotencyKey = null
    ): PaymentAttempt {
        if ($idempotencyKey !== null) {
            $existing = PaymentAttempt::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ((int) $existing->invoice_id !== (int) $invoice->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for another invoice.',
                    ]);
                }

                return $existing;
            }
        }

        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice cannot be paid while it is '.$invoice->status.'.',
            ]);
        }

        $outstanding = $this->outstandingFor($invoice);

        if ($outstanding < 1) {
            throw ValidationException::withMessages([
                'invoice' => 'There is nothing left to pay on this invoice.',
            ]);
        }

        $amount = $amountMinor ?? $outstanding;

        if ($amount < 1 || $amount > $outstanding) {
            throw ValidationException::withMessages([
                'amount_minor' => 'The amount must be between 1 and '.$outstanding.' minor units.',
            ]);
        }

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'payer_id' => $payer->id,
            'provider' => 'paystack',
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $amount,
            'currency' => $invoice->currency,
            'status' => 'initialized',
        ]);

        // Our own reference keeps the mapping explicit and unguessable.
        $reference = 'PMF-'.$attempt->id.'-'.Str::upper(Str::random(10));

        try {
            $data = $this->paystack->initializeTransaction([
                'email' => $payer->email,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'reference' => $reference,
                'callback_url' => rtrim((string) config('paymef.frontend_url'), '/').'/payments/callback',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_reference' => $invoice->reference,
                    'school_id' => $invoice->school_id,
                    'payment_attempt_id' => $attempt->id,
                    'outstanding_minor' => $outstanding,
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
        [$settled, $freshlyCredited] = DB::transaction(function () use ($attempt, $payload): array {
            $locked = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'success') {
                return [$locked, false];
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

                return [$locked, false];
            }

            $paidAmount = (int) ($payload['amount'] ?? 0);
            $paidCurrency = strtoupper((string) ($payload['currency'] ?? ''));

            // Never credit a payment that does not match what was charged.
            if ($paidAmount !== (int) $locked->amount_minor
                || $paidCurrency !== strtoupper((string) $invoice->currency)) {
                $locked->update([
                    'status' => 'failed',
                    'provider_payload' => $payload,
                ]);

                return [$locked, false];
            }

            $locked->update([
                'status' => 'success',
                'provider_payload' => $payload,
            ]);

            // The unique idempotency key is the database-level guard against
            // a replayed webhook posting the same money twice.
            $entry = LedgerEntry::firstOrCreate(
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
                        'payer_id' => $locked->payer_id,
                    ],
                    'occurred_at' => now(),
                ]
            );

            $this->syncInvoiceStatus($invoice);

            return [$locked, $entry->wasRecentlyCreated];
        });

        // Receipts are sent outside the transaction so a mail failure can never
        // roll back money that Paystack has already taken.
        if ($freshlyCredited) {
            $this->queueReceipts($settled);
        }

        return $settled;
    }

    /**
     * Recalculate an invoice's state from successful payments minus processed
     * refunds. No stored running total can drift from the ledger this way.
     */
    public function syncInvoiceStatus(Invoice $invoice): void
    {
        $invoice->refresh();

        $paid = (int) PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->sum('amount_minor');

        $refunded = (int) Refund::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'processed')
            ->sum('amount_minor');

        $net = $paid - $refunded;

        $status = match (true) {
            $net >= (int) $invoice->amount_minor => 'paid',
            $net > 0 => 'partially_paid',
            // Fully refunded invoices go back to being owed.
            in_array($invoice->status, ['paid', 'partially_paid'], true) => 'issued',
            default => $invoice->status,
        };

        if ($status !== $invoice->status) {
            $invoice->update(['status' => $status]);
        }
    }

    /**
     * What is still owed: the invoice total, minus successful payments, plus
     * anything already refunded.
     */
    public function outstandingFor(Invoice $invoice): int
    {
        $paid = (int) PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->sum('amount_minor');

        $refunded = (int) Refund::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'processed')
            ->sum('amount_minor');

        return max(0, (int) $invoice->amount_minor - $paid + $refunded);
    }

    private function queueReceipts(PaymentAttempt $attempt): void
    {
        $attempt->loadMissing(['invoice.student', 'payer']);

        $invoice = $attempt->invoice;

        if (! $invoice) {
            return;
        }

        $emails = collect([$attempt->payer?->email, $invoice->student?->email])
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            Mail::to($email)->queue(new PaymentReceiptMail($invoice, $attempt));
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

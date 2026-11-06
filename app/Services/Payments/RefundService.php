<?php

namespace App\Services\Payments;

use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\User;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly PaymentService $payments
    ) {}

    /**
     * Ask the provider to refund all or part of a successful payment.
     */
    public function request(
        PaymentAttempt $attempt,
        User $user,
        ?int $amountMinor = null,
        ?string $reason = null
    ): Refund {
        if ($attempt->status !== 'success' || $attempt->provider_reference === null) {
            throw ValidationException::withMessages([
                'payment' => 'Only a successful payment can be refunded.',
            ]);
        }

        $refundable = $this->refundableFor($attempt);

        if ($refundable < 1) {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been fully refunded.',
            ]);
        }

        $amount = $amountMinor ?? $refundable;

        if ($amount < 1 || $amount > $refundable) {
            throw ValidationException::withMessages([
                'amount_minor' => 'The refund must be between 1 and '.$refundable.' minor units.',
            ]);
        }

        $data = $this->paystack->refundTransaction((string) $attempt->provider_reference, $amount);

        $refund = Refund::create([
            'school_id' => $attempt->invoice->school_id,
            'invoice_id' => $attempt->invoice_id,
            'payment_attempt_id' => $attempt->id,
            'requested_by' => $user->id,
            'provider' => 'paystack',
            'provider_refund_id' => isset($data['id']) ? (string) $data['id'] : null,
            'amount_minor' => $amount,
            'currency' => $attempt->currency,
            'status' => $this->mapStatus((string) ($data['status'] ?? 'pending')),
            'reason' => $reason,
            'provider_payload' => $data,
        ]);

        $this->applyIfProcessed($refund);

        return $refund->refresh();
    }

    /**
     * Handle Paystack's refund.processed webhook. Returns null when the refund
     * is not one of ours.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyProviderEvent(array $data): ?Refund
    {
        $providerId = isset($data['id']) ? (string) $data['id'] : '';

        if ($providerId === '') {
            return null;
        }

        $refund = Refund::where('provider_refund_id', $providerId)->first();

        if (! $refund) {
            return null;
        }

        if (($data['status'] ?? '') === 'processed') {
            $refund->update([
                'status' => 'processed',
                'provider_payload' => $data,
            ]);

            $this->applyIfProcessed($refund->refresh());
        }

        return $refund;
    }

    /**
     * How much of a payment can still be refunded.
     */
    public function refundableFor(PaymentAttempt $attempt): int
    {
        $alreadyRefunded = (int) Refund::query()
            ->where('payment_attempt_id', $attempt->id)
            ->whereIn('status', ['pending', 'processed'])
            ->sum('amount_minor');

        return max(0, (int) $attempt->amount_minor - $alreadyRefunded);
    }

    private function applyIfProcessed(Refund $refund): void
    {
        if (! $refund->isProcessed()) {
            return;
        }

        DB::transaction(function () use ($refund): void {
            // A refund is money leaving the student's wallet: the ledger entry
            // is written once, keyed by the refund row.
            LedgerEntry::firstOrCreate(
                ['idempotency_key' => 'refund:'.$refund->id],
                [
                    'school_id' => $refund->school_id,
                    'user_id' => $refund->invoice->student_id,
                    'entry_type' => 'refund',
                    'amount_minor' => $refund->amount_minor,
                    'currency' => $refund->currency,
                    'source_type' => 'refund',
                    'source_id' => (string) $refund->id,
                    'meta' => [
                        'provider' => $refund->provider,
                        'provider_refund_id' => $refund->provider_refund_id,
                        'payment_reference' => $refund->paymentAttempt?->provider_reference,
                        'reason' => $refund->reason,
                        'requested_by' => $refund->requested_by,
                    ],
                    'occurred_at' => now(),
                ]
            );

            $this->payments->syncInvoiceStatus($refund->invoice);
        });
    }

    private function mapStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'processed', 'success' => 'processed',
            'failed' => 'failed',
            default => 'pending',
        };
    }
}

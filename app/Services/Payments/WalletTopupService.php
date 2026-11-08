<?php

namespace App\Services\Payments;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Wallet funding through Paystack.
 *
 * Money only becomes spendable after Paystack confirms the charge; the credit
 * is written to the ledger once, keyed by the top-up reference so webhook
 * retries and polling cannot double-credit.
 */
class WalletTopupService
{
    private const MIN_AMOUNT_MINOR = 10000; // ₦100, Paystack's minimum

    private const MAX_AMOUNT_MINOR = 1000000000; // ₦10,000,000

    public function __construct(private readonly PaystackClient $paystack) {}

    public function initialize(User $user, int $amountMinor, ?string $currency = null): WalletTopup
    {
        $currency = strtoupper($currency ?: 'NGN');

        if ($currency !== 'NGN') {
            throw ValidationException::withMessages([
                'currency' => 'Wallet funding currently settles in NGN only.',
            ]);
        }

        if ($amountMinor < self::MIN_AMOUNT_MINOR || $amountMinor > self::MAX_AMOUNT_MINOR) {
            throw ValidationException::withMessages([
                'amount_minor' => 'Enter an amount between ₦100 and ₦10,000,000.',
            ]);
        }

        $topup = WalletTopup::create([
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'PMF-TP-'.Str::upper(Str::random(16)),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => 'pending',
            'purpose' => 'wallet_funding',
        ]);

        // Seeded demo accounts use reserved .test addresses, which Paystack
        // rejects as invalid. Real accounts keep their own email.
        $email = $user->email;
        if (app()->environment() !== 'production' && str_ends_with(strtolower($email), '.test')) {
            $email = 'demo+'.$user->id.'@paymef.com';
        }

        try {
            $data = $this->paystack->initializeTransaction([
                'email' => $email,
                'amount' => $amountMinor,
                'currency' => $currency,
                'reference' => $topup->reference,
                'callback_url' => rtrim((string) config('paymef.frontend_url'), '/').'/#/payments/callback',
                'channels' => ['card', 'bank', 'ussd', 'qr', 'bank_transfer'],
                'metadata' => [
                    'purpose' => 'wallet_funding',
                    'paymef_user_id' => $user->id,
                    'wallet_topup_id' => $topup->id,
                    'paymef_tx_id' => 'topup_'.$topup->id,
                ],
            ]);
        } catch (PaystackException $e) {
            $topup->update([
                'status' => 'failed',
                'provider_payload' => ['error' => $e->getMessage(), 'http_status' => $e->status()],
            ]);

            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        } catch (Throwable $e) {
            $topup->update(['status' => 'failed', 'provider_payload' => ['error' => $e->getMessage()]]);

            throw $e;
        }

        $topup->update([
            'provider_reference' => $data['reference'] ?? $topup->reference,
            'provider_payload' => $data,
            'status' => 'pending',
        ]);

        return $topup->refresh();
    }

    /**
     * Re-check with Paystack and settle the ledger.
     */
    public function confirm(string $reference): ?WalletTopup
    {
        $topup = WalletTopup::where('reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->first();

        if ($topup === null) {
            return null;
        }

        if ($topup->status === 'success') {
            return $topup;
        }

        $data = $this->paystack->verifyTransaction($topup->reference);
        $providerStatus = (string) ($data['status'] ?? 'failed');

        if ($providerStatus !== 'success') {
            $topup->update([
                'status' => in_array($providerStatus, ['failed', 'abandoned', 'reversed'], true)
                    ? $providerStatus
                    : 'pending',
                'provider_payload' => $data,
            ]);

            return $topup->refresh();
        }

        $paidMinor = (int) ($data['amount'] ?? 0);
        $paidCurrency = strtoupper((string) ($data['currency'] ?? $topup->currency));

        if ($paidMinor !== (int) $topup->amount_minor || $paidCurrency !== $topup->currency) {
            report(new \RuntimeException(
                "Paystack amount mismatch for top-up {$topup->reference}: expected {$topup->amount_minor} {$topup->currency}, got {$paidMinor} {$paidCurrency}."
            ));

            $topup->update([
                'status' => 'failed',
                'failure_reason' => 'Paid amount did not match the requested top-up.',
                'provider_payload' => $data,
            ]);

            return $topup->refresh();
        }

        return DB::transaction(function () use ($topup, $data): WalletTopup {
            /** @var WalletTopup $locked */
            $locked = WalletTopup::query()->lockForUpdate()->findOrFail($topup->id);

            if ($locked->status === 'success' && $locked->ledger_entry_id !== null) {
                return $locked;
            }

            $entry = LedgerEntry::firstOrCreate(
                ['idempotency_key' => 'wallet_topup:'.$locked->reference],
                [
                    'user_id' => $locked->user_id,
                    'entry_type' => 'credit',
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'source_type' => 'wallet_topup',
                    'source_id' => (string) $locked->id,
                    'meta' => [
                        'provider' => 'paystack',
                        'reference' => $locked->reference,
                        'channel' => $data['channel'] ?? null,
                    ],
                    'occurred_at' => now(),
                ]
            );

            $locked->update([
                'status' => 'success',
                'provider_reference' => $data['reference'] ?? $locked->provider_reference,
                'provider_payload' => $data,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'settled_at' => now(),
                'ledger_entry_id' => $entry->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactionsFor(User $user): array
    {
        return WalletTopup::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (WalletTopup $topup) => [
                'paymef_tx_id' => 'topup_'.$topup->id,
                'paystack_reference' => $topup->provider_reference ?: $topup->reference,
                'email' => $user->email,
                'amount_minor' => (int) $topup->amount_minor,
                'currency' => $topup->currency,
                'channel' => $topup->provider_payload['channel'] ?? 'card',
                'status' => $topup->status,
                'purpose' => $topup->purpose,
                'created_at' => $topup->created_at?->toIso8601String(),
                'paid_at' => $topup->paid_at?->toIso8601String(),
                'settled_in_ledger' => $topup->ledger_entry_id !== null,
            ])
            ->all();
    }
}

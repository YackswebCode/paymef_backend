<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\WalletTopup;
use App\Services\Payments\WalletTopupService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaystackGatewayController extends Controller
{
    public function __construct(
        private readonly WalletTopupService $topups,
        private readonly WalletService $wallets
    ) {}

    public function config(Request $request): JsonResponse
    {
        $secret = (string) config('paymef.paystack.secret_key');
        $public = (string) config('paymef.paystack.public_key');

        return response()->json([
            'publicKey' => $public,
            'environment' => str_starts_with($secret, 'sk_live') ? 'live' : 'sandbox',
            'currency' => 'NGN',
            'supportedChannels' => ['card', 'bank', 'ussd', 'qr', 'bank_transfer'],
            'merchantName' => (string) config('paymef.paystack.merchant_name'),
            'configured' => $secret !== '' && $public !== '',
        ]);
    }

    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:10000', 'max:1000000000'],
            'currency' => ['nullable', 'string', Rule::in(['NGN'])],
            'purpose' => ['nullable', Rule::in(['wallet_funding'])],
        ]);

        $topup = $this->topups->initialize(
            $request->user(),
            (int) $data['amount_minor'],
            $data['currency'] ?? 'NGN'
        );

        $payload = $topup->provider_payload ?? [];

        return response()->json([
            'status' => true,
            'message' => 'Paystack checkout is ready.',
            'data' => [
                'authorization_url' => $payload['authorization_url'] ?? null,
                'access_code' => $payload['access_code'] ?? null,
                'reference' => $topup->reference,
                'paymef_tx_id' => 'topup_'.$topup->id,
                'amount_minor' => (int) $topup->amount_minor,
                'currency' => $topup->currency,
            ],
        ], 201);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $topup = $this->topups->confirm($reference);

        if ($topup === null || (int) $topup->user_id !== (int) $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Transaction reference was not found.',
            ], 404);
        }

        $provider = $topup->provider_payload ?? [];

        return response()->json([
            'status' => true,
            'message' => $topup->status === 'success'
                ? 'Payment verified and credited to your wallet.'
                : 'Payment has not been completed yet.',
            'data' => [
                'id' => $provider['id'] ?? null,
                'domain' => $provider['domain'] ?? null,
                'status' => $topup->status === 'success' ? 'success' : ($provider['status'] ?? 'pending'),
                'reference' => $topup->provider_reference ?: $topup->reference,
                'amount' => (int) $topup->amount_minor,
                'gateway_response' => $provider['gateway_response'] ?? null,
                'paid_at' => $topup->paid_at?->toIso8601String(),
                'created_at' => $topup->created_at?->toIso8601String(),
                'channel' => $provider['channel'] ?? null,
                'currency' => $topup->currency,
                'customer' => ['email' => $request->user()->email],
                'authorization' => $provider['authorization'] ?? null,
                'metadata' => $provider['metadata'] ?? ['purpose' => $topup->purpose],
            ],
            'paymef_internal' => [
                'paymef_tx_id' => 'topup_'.$topup->id,
                'purpose' => $topup->purpose,
                'settled' => $topup->ledger_entry_id !== null,
                'settled_at' => $topup->settled_at?->toIso8601String(),
                'ledger_entry_id' => $topup->ledger_entry_id,
                'wallet' => $this->wallets->forUser($request->user()),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        return response()->json([
            'transactions' => $this->topups->transactionsFor($request->user()),
        ]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $settled = WalletTopup::query()->where('status', 'success');
        $settledTotal = (int) (clone $settled)->sum('amount_minor');
        $unsettled = (clone $settled)->whereNull('ledger_entry_id')->count();
        $ledgerTotal = (int) LedgerEntry::query()
            ->where('source_type', 'wallet_topup')
            ->sum('amount_minor');

        return response()->json([
            'reconciliation' => [
                'provider' => 'paystack',
                'settled_topups' => (clone $settled)->count(),
                'settled_minor' => $settledTotal,
                'ledger_minor' => $ledgerTotal,
                'difference_minor' => $settledTotal - $ledgerTotal,
                'unsettled_topups' => $unsettled,
                'pending_topups' => WalletTopup::where('status', 'pending')->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}

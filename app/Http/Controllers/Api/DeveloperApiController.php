<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Services\Payments\PaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Public, versioned developer API authenticated by a PayMef secret key.
 * Every read is scoped to the user that owns the key.
 */
class DeveloperApiController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaymentService $payments,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'school' => $user->school?->only(['id', 'name', 'slug']),
            ],
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $currency = strtoupper((string) $request->query('currency', 'NGN'));
        abort_unless(preg_match('/^[A-Z]{3}$/', $currency) === 1, 422, 'Invalid currency code.');

        return response()->json([
            'status' => true,
            'data' => [
                'wallet' => $this->wallets->forUser($this->user($request), $currency),
            ],
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->visibleTo($this->user($request))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->latest('id')
            ->paginate(20)
            ->through(fn (Invoice $invoice): array => array_merge(
                $invoice->toArray(),
                ['outstanding_minor' => $this->payments->outstandingFor($invoice)]
            ));

        return response()->json([
            'status' => true,
            'data' => $invoices,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $entries = LedgerEntry::query()
            ->visibleTo($this->user($request))
            ->when(
                $request->filled('entry_type'),
                fn ($query) => $query->where('entry_type', $request->string('entry_type'))
            )
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'status' => true,
            'data' => $entries,
        ]);
    }

    private function user(Request $request): \App\Models\User
    {
        /** @var ApiClient|null $client */
        $client = $request->attributes->get('api_client');

        if ($client === null || $client->user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid API key.');
        }

        return $client->user;
    }
}

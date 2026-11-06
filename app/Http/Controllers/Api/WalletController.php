<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly WalletService $wallets) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'wallet' => $this->wallets->forUser($request->user(), $this->currency($request)),
        ]);
    }

    public function school(Request $request, School $school): JsonResponse
    {
        $this->authorize('view', $school);

        return response()->json([
            'wallet' => $this->wallets->forSchool($school, $this->currency($request)),
        ]);
    }

    private function currency(Request $request): string
    {
        $currency = strtoupper((string) $request->query('currency', 'NGN'));

        abort_unless(preg_match('/^[A-Z]{3}$/', $currency) === 1, 422, 'Invalid currency code.');

        return $currency;
    }
}

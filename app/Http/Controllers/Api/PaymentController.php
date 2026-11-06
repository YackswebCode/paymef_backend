<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PaymentService $payments) {}

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $attempt = $this->payments->initialize($invoice, $request->user());

        return response()->json([
            'payment' => [
                'reference' => $attempt->provider_reference,
                'status' => $attempt->status,
                'amount_minor' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'authorization_url' => $attempt->provider_payload['authorization_url'] ?? null,
                'access_code' => $attempt->provider_payload['access_code'] ?? null,
            ],
        ], 201);
    }

    public function show(string $reference): JsonResponse
    {
        $attempt = PaymentAttempt::where('provider_reference', $reference)->firstOrFail();

        $this->authorize('view', $attempt->invoice);

        // Used as the redirect/callback endpoint: re-check with Paystack rather
        // than trusting a browser-supplied status.
        if (in_array($attempt->status, ['initialized', 'pending'], true)) {
            try {
                $attempt = $this->payments->confirm($reference) ?? $attempt;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'payment' => [
                'reference' => $attempt->provider_reference,
                'status' => $attempt->status,
                'amount_minor' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'invoice_id' => $attempt->invoice_id,
            ],
        ]);
    }
}

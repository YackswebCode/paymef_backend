<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('paymef.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Signature must be checked against the raw body, not the parsed input.
        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($request->input('event') !== 'charge.success') {
            return response()->json(['status' => 'ignored']);
        }

        $reference = (string) ($request->input('data.reference') ?? '');

        if ($reference === '') {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $attempt = $this->payments->confirm($reference);
        } catch (Throwable $e) {
            report($e);

            // Non-2xx asks Paystack to retry, which is what we want if our
            // verification or database write failed.
            return response()->json(['message' => 'Could not process webhook.'], 500);
        }

        return response()->json(['status' => $attempt ? 'processed' : 'ignored']);
    }
}

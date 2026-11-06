<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly RefundService $refunds
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('paymef.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Signature must be checked against the raw body, not the parsed input.
        $raw = $request->getContent();
        $expected = hash_hmac('sha512', $raw, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventName = (string) $request->input('event', '');
        $fingerprint = hash('sha256', $raw);

        $record = WebhookEvent::firstOrCreate(
            ['provider' => 'paystack', 'provider_event_id' => $fingerprint],
            [
                'event' => $eventName,
                'reference' => $request->input('data.reference'),
                'status' => 'received',
                'payload' => $request->all(),
            ]
        );

        // Paystack retries deliveries; only a previously failed attempt may be
        // retried, everything else is a duplicate.
        if (! $record->wasRecentlyCreated) {
            if ($record->status !== 'failed') {
                return response()->json(['status' => 'duplicate']);
            }

            $record->increment('attempts');
        }

        try {
            $status = match ($eventName) {
                'charge.success' => $this->handleCharge($request),
                'refund.processed' => $this->refunds->applyProviderEvent((array) $request->input('data'))
                    ? 'processed'
                    : 'ignored',
                default => 'ignored',
            };
        } catch (Throwable $e) {
            report($e);

            $record->update(['status' => 'failed']);

            // Non-2xx asks Paystack to retry, which is what we want if our
            // verification or database write failed.
            return response()->json(['message' => 'Could not process webhook.'], 500);
        }

        $record->update(['status' => $status]);

        return response()->json(['status' => $status]);
    }

    private function handleCharge(Request $request): string
    {
        $reference = (string) ($request->input('data.reference') ?? '');

        if ($reference === '') {
            return 'ignored';
        }

        $attempt = $this->payments->confirm($reference);

        return $attempt ? 'processed' : 'ignored';
    }
}

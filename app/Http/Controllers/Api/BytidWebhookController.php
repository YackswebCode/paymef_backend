<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BytidWebhookController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('paymef.bytid.webhook_secret');
        $signature = (string) $request->header('Bytid-Signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['message' => 'Webhook signing secret is not configured.'], 503);
        }

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/i', $signature, $matches)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $timestamp = (int) $matches[1];
        $provided = strtolower($matches[2]);
        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        if (! hash_equals($expected, $provided) || abs(time() - $timestamp) > 300) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];
        $event = (string) ($request->header('Bytid-Event') ?: ($payload['type'] ?? ''));
        $eventId = (string) ($request->header('Bytid-Event-Id') ?: ($payload['id'] ?? hash('sha256', $raw)));

        $record = WebhookEvent::firstOrCreate(
            ['provider' => 'bytid', 'provider_event_id' => $eventId],
            [
                'event' => $event,
                'reference' => $payload['data']['reference'] ?? null,
                'status' => 'received',
                'payload' => $payload,
            ]
        );

        if (! $record->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate']);
        }

        $status = 'ignored';

        if ($event === 'verification.completed' && is_array($payload['data'] ?? null)) {
            $verification = $this->kyc->applyProviderEvent($payload['data']);
            $status = $verification ? 'processed' : 'ignored';
        }

        $record->update(['status' => $status]);

        return response()->json(['status' => $status]);
    }
}

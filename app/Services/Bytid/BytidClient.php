<?php

namespace App\Services\Bytid;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin server-side client for the Bytid identity API.
 *
 * Only identity checks and verification reads are used. The API key is read
 * from config on each call and never leaves the server.
 */
class BytidClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  Full response envelope (data + request_id).
     */
    public function verifyIdentity(string $type, array $payload): array
    {
        $path = match ($type) {
            'bvn' => '/identity/bvn',
            'nin' => '/identity/nin',
            default => throw new BytidException('Unsupported identity document type.', 422, 'validation_error'),
        };

        return $this->send($this->client()->post($path, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function getVerification(string $id): array
    {
        return $this->send($this->client()->get('/verifications/'.rawurlencode($id)));
    }

    public function isConfigured(): bool
    {
        return (bool) config('paymef.bytid.enabled') && $this->key() !== '';
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new BytidException(
                'Identity verification is not enabled yet. Add BYTID credentials on the server.',
                503,
                'provider_disabled'
            );
        }

        return Http::baseUrl(rtrim((string) config('paymef.bytid.base_url'), '/'))
            ->withToken($this->key())
            ->acceptJson()
            ->timeout(30);
    }

    private function key(): string
    {
        return trim((string) config('paymef.bytid.api_key'));
    }

    /**
     * Bytid answers HTTP 200 for checks that ran; success is in the envelope.
     *
     * @return array<string, mixed>
     */
    private function send(Response $response): array
    {
        $payload = (array) $response->json();

        if ($response->successful() && ($payload['success'] ?? false) === true) {
            return $payload;
        }

        $error = (array) ($payload['error'] ?? []);

        throw new BytidException(
            (string) ($error['message'] ?? 'The identity check could not be completed.'),
            $response->status() > 0 ? $response->status() : 502,
            (string) ($error['code'] ?? 'provider_error')
        );
    }
}

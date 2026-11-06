<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initializeTransaction(array $payload): array
    {
        $response = $this->client()->post('/transaction/initialize', $payload);

        return $this->data($response, 'Payment could not be initialized.');
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference));

        return $this->data($response, 'Payment could not be verified.');
    }

    private function client(): PendingRequest
    {
        $secret = (string) config('paymef.paystack.secret_key');

        if ($secret === '') {
            throw new RuntimeException('Paystack is not configured: PAYSTACK_SECRET_KEY is missing.');
        }

        return Http::baseUrl(rtrim((string) config('paymef.paystack.base_url'), '/'))
            ->withToken($secret)
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response, string $fallback): array
    {
        if ($response->failed() || $response->json('status') !== true) {
            throw new PaystackException(
                (string) ($response->json('message') ?? $fallback),
                $response->status()
            );
        }

        return (array) $response->json('data');
    }
}

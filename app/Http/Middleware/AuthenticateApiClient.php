<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public developer API request using a PayMef secret key.
 *
 * Keys are shown once at creation and stored only as a SHA-256 hash, so a
 * database leak cannot be replayed against the API.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || preg_match('/^pmf_(test|live)_[A-Za-z0-9]{32,}$/', $token) !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Missing or malformed API key. Send it as "Authorization: Bearer pmf_test_...".',
            ], 401);
        }

        $client = ApiClient::query()
            ->with('user')
            ->where('key_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($client === null || $client->user === null) {
            return response()->json([
                'status' => false,
                'message' => 'This API key is invalid or has been revoked.',
            ], 401);
        }

        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}

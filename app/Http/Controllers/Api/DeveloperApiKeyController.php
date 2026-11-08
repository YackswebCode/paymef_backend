<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = ApiClient::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (ApiClient $client): array => $client->toPublicArray());

        return response()
            ->json(['keys' => $clients])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'environment' => ['sometimes', 'string', 'in:test,live'],
        ]);

        $environment = $data['environment'] ?? 'test';
        $secret = 'pmf_'.$environment.'_'.bin2hex(random_bytes(24));

        $client = ApiClient::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'key_prefix' => substr($secret, 0, 18),
            'key_hash' => hash('sha256', $secret),
            'environment' => $environment,
            'scopes' => ['read'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'API key created. Copy it now — it will not be shown again.',
            'secret' => $secret,
            'key' => $client->toPublicArray(),
        ], 201);
    }

    public function destroy(Request $request, ApiClient $apiClient): JsonResponse
    {
        abort_unless((int) $apiClient->user_id === (int) $request->user()->id, 404);

        if ($apiClient->revoked_at === null) {
            $apiClient->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'API key revoked.',
            'key' => $apiClient->refresh()->toPublicArray(),
        ]);
    }
}

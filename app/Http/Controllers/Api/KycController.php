<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyKycRequest;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'kyc' => $this->kyc->summary($user),
            'verifications' => $this->kyc->history($user),
        ]);
    }

    public function verify(VerifyKycRequest $request): JsonResponse
    {
        $user = $request->user();

        $verification = $this->kyc->verify($user, [
            'type' => $request->validated('type'),
            'id_number' => $request->validated('id_number'),
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'dob' => $request->validated('dob'),
            'consent' => (bool) $request->validated('consent'),
        ]);

        return response()->json([
            'verification' => $this->kyc->present($verification),
            'kyc' => $this->kyc->summary($user->refresh()),
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->validated());

        return response()->json(['user' => $user->refresh()->load('school')]);
    }

    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('password')])->save();

        // Every other device is signed out; the caller keeps their own token.
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->tokens()
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }
}

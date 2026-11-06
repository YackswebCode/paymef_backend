<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Handle the signed verification link delivered by email.
     *
     * The link is signed and carries the SHA-1 of the user's email, so it can
     * be opened straight from a mail client without a session or API token.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        abort_unless(
            hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
            'Invalid verification link.'
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return response()->json(['message' => 'Email address verified.']);
    }

    /**
     * Re-send the verification link to the authenticated user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}

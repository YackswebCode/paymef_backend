<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Block unverified users from protected routes. Administrators are
     * provisioned internally and skip the verification requirement.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresEmailVerification() || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your email address is not verified.',
            'code' => 'email_unverified',
        ], 403);
    }
}

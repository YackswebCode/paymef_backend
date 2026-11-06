<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_for_a_registered_user(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'reset@example.com'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If that email address is registered, a password reset link has been sent.'
            );

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_gets_the_same_response_without_sending_mail(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If that email address is registered, a password reset link has been sent.'
            );

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email_address(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'OldPassword123!',
        ]);

        $token = Password::broker()->createToken($user);
        $user->createToken('existing-device');

        $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));

        // Existing sessions are revoked so a stolen token cannot be reused.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'NewPassword123!',
        ])->assertOk();
    }

    public function test_reset_is_rejected_with_an_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'OldPassword123!',
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => 'invalid-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }

    public function test_reset_link_points_at_the_frontend(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('paymef.frontend_url'))
                && str_contains($url, 'token=')
                && str_contains($url, urlencode($user->email));
        });
    }
}

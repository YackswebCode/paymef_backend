<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in for a future business route protected by the `verified` gate.
        Route::middleware(['auth:sanctum', 'verified'])
            ->get('/api/test/verified-only', fn () => response()->json(['ok' => true]));
    }

    private function register(array $overrides = [])
    {
        Notification::fake();

        return $this->postJson('/api/auth/register', array_merge([
            'name' => 'Ada Student',
            'email' => 'ada@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
        ], $overrides));
    }

    public function test_registration_sends_verification_email_and_leaves_user_unverified(): void
    {
        $this->register()
            ->assertCreated()
            ->assertJsonPath('email_verification_sent', true);

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_is_blocked_from_verified_routes(): void
    {
        $user = User::factory()->unverified()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/test/verified-only')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_verified_user_can_access_verified_routes(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/test/verified-only')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_admin_skips_email_verification(): void
    {
        $admin = User::factory()->unverified()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/test/verified-only')->assertOk();
    }

    public function test_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('message', 'Email address verified.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/auth/email/verify/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_link_with_wrong_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->getJson($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/auth/email/verification-notification')->assertUnauthorized();
    }

    public function test_resend_sends_a_new_link_for_unverified_user(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Verification link sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_is_a_no_op_for_verified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Email address already verified.');

        Notification::assertNothingSent();
    }
}

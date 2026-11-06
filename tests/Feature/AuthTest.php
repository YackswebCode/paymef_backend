<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_register_and_receives_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Student',
            'email' => 'Ada@Example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
            'phone' => '08030000000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role', 'school_id'],
                'token',
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'role' => 'student',
        ]);
    }

    public function test_registration_attaches_the_school(): void
    {
        $school = School::create(['name' => 'Test Academy', 'slug' => 'test-academy']);

        $this->postJson('/api/auth/register', [
            'name' => 'Parent One',
            'email' => 'parent@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
            'school_id' => $school->id,
        ])->assertCreated()
            ->assertJsonPath('user.school_id', $school->id)
            ->assertJsonPath('user.school.name', 'Test Academy');
    }

    public function test_public_registration_rejects_privileged_roles(): void
    {
        foreach (['school_admin', 'ngo_staff', 'admin'] as $role) {
            $this->postJson('/api/auth/register', [
                'name' => 'Privileged User',
                'email' => $role.'@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => $role,
            ])->assertStatus(422)
                ->assertJsonValidationErrors('role');

            $this->assertDatabaseMissing('users', ['email' => $role.'@example.com']);
        }
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Another User',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_issued_token_authenticates_the_me_endpoint(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'token@example.com');
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'Login@Example.com',
            'password' => 'Password123!',
            'device_name' => 'web',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'login@example.com')
            ->assertJsonStructure(['user' => ['id', 'email'], 'token', 'token_type']);

        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_login_fails_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        User::factory()->create([
            'email' => 'logout@example.com',
            'password' => 'Password123!',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'Password123!',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // The guard caches the resolved user on the shared test app instance;
        // forget it so the next request re-resolves the (now deleted) token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registered_password_is_hashed(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Hash User',
            'email' => 'hash@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
        ])->assertCreated();

        $user = User::where('email', 'hash@example.com')->firstOrFail();

        $this->assertNotSame('Password123!', $user->password);
        $this->assertTrue(Hash::check('Password123!', $user->password));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveAccount;
use App\Models\DeviceToken;
use App\Models\LoginIdentifier;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'student', bool $pending = false): User
    {
        $user = User::create(['role' => $role, 'name' => 'Synthetic account', 'password_hash' => 'Synthetic password phrase', 'account_status' => $pending ? 'pending_activation' : 'active', 'must_change_password' => $pending]);
        $value = $role === 'student' ? '0012345678' : '00098765';
        LoginIdentifier::create(['user_id' => $user->id, 'role' => $role, 'kind' => $role === 'student' ? 'nisn' : 'nip', 'normalized_value' => $value]);
        if ($role === 'student') {
            Student::create(['user_id' => $user->id, 'nisn' => $value, 'education_status' => 'active']);
        } else {
            Teacher::create(['user_id' => $user->id, 'nip' => $value, 'teacher_code' => 'G001']);
            LoginIdentifier::create(['user_id' => $user->id, 'role' => $role, 'kind' => 'teacher_code', 'normalized_value' => 'g001']);
        }
        if ($pending) {
            TemporaryCredential::create(['user_id' => $user->id, 'encrypted_secret' => 'Synthetic password phrase', 'expires_at' => now()->addDay(), 'generated_by' => $user->id, 'reason' => 'Synthetic initial account']);
        }

        return $user;
    }

    public function test_student_credentials_cannot_login_at_teacher_endpoint(): void
    {
        $this->account();
        $this->postJson('/api/v1/auth/teacher/login', ['identifier' => '0012345678', 'password' => 'Synthetic password phrase'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_failure_does_not_reveal_account_existence(): void
    {
        $this->account();
        $known = $this->postJson('/api/v1/auth/student/login', ['nisn' => '0012345678', 'password' => 'incorrect synthetic password']);
        $unknown = $this->postJson('/api/v1/auth/student/login', ['nisn' => '0099999999', 'password' => 'incorrect synthetic password']);
        $known->assertUnauthorized();
        $unknown->assertUnauthorized();
        self::assertSame($known->json('error'), $unknown->json('error'));
    }

    public function test_teacher_can_login_using_either_alias_and_token_expires_in_seven_days(): void
    {
        $this->freezeSecond();
        $user = $this->account('teacher');
        foreach (['00098765', 'G001'] as $identifier) {
            $response = $this->postJson('/api/v1/auth/teacher/login', ['identifier' => $identifier, 'password' => 'Synthetic password phrase'])->assertOk()->assertJsonPath('data.role', 'teacher');
            self::assertNotEmpty($response->json('data.token'));
        }
        self::assertTrue($user->tokens()->first()->expires_at->equalTo(now()->addDays(7)));
    }

    public function test_pending_account_only_gets_activation_identity_and_password_change(): void
    {
        Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->get('/api/v1/test-active', fn () => ['data' => true]);
        $user = $this->account('student', true);
        $response = $this->postJson('/api/v1/auth/student/login', ['nisn' => '0012345678', 'password' => 'Synthetic password phrase'])->assertOk();
        self::assertTrue($response->json('data.must_change_password'));
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.enrollments');
        $this->withToken($token)->getJson('/api/v1/test-active')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/auth/change-password', ['current_password' => 'Synthetic password phrase', 'password' => 'New synthetic password phrase', 'password_confirmation' => 'New synthetic password phrase'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        self::assertSame('active', $user->fresh()->account_status);
        $this->assertDatabaseCount('temporary_credentials', 0);
    }

    public function test_expired_temporary_login_is_rejected_at_exact_twenty_four_hours(): void
    {
        $this->freezeTime();
        $this->account('student', true);
        $this->travel(24)->hours();
        $this->postJson('/api/v1/auth/student/login', ['nisn' => '0012345678', 'password' => 'Synthetic password phrase'])->assertUnauthorized();
    }

    public function test_inactive_actor_and_expired_tokens_cannot_read_me(): void
    {
        $user = $this->account();
        $token = $user->createToken('synthetic', ['*'], now()->addDays(7));
        $user->update(['account_status' => 'inactive']);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $user->update(['account_status' => 'active']);
        $this->app['auth']->forgetGuards();
        $expired = $user->createToken('synthetic expired', ['*'], now()->subSecond());
        $this->withToken($expired->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_change_password_requires_current_password_and_twelve_characters(): void
    {
        $user = $this->account();
        $token = $user->createToken('synthetic');
        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/change-password', ['current_password' => 'wrong password', 'password' => 'New synthetic password phrase', 'password_confirmation' => 'New synthetic password phrase'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/change-password', ['current_password' => 'Synthetic password phrase', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
    }

    public function test_login_is_rate_limited_and_public_registration_does_not_exist(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/student/login', ['nisn' => '0088888888', 'password' => 'incorrect synthetic password'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/student/login', ['nisn' => '0088888888', 'password' => 'incorrect synthetic password'])->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
    }

    public function test_logout_revokes_only_current_session(): void
    {
        $user = $this->account();
        $current = $user->createToken('synthetic current');
        $other = $user->createToken('synthetic other');
        $currentDevice = DeviceToken::create(['user_id' => $user->id, 'app' => 'student', 'token' => 'synthetic current push', 'active' => true, 'personal_access_token_id' => $current->accessToken->id]);
        $otherDevice = DeviceToken::create(['user_id' => $user->id, 'app' => 'student', 'token' => 'synthetic other push', 'active' => true, 'personal_access_token_id' => $other->accessToken->id]);
        $this->withToken($current->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/v1/me')->assertOk();
        self::assertFalse($currentDevice->fresh()->active);
        self::assertNull($currentDevice->fresh()->personal_access_token_id);
        self::assertTrue($otherDevice->fresh()->active);
        self::assertSame($other->accessToken->id, $otherDevice->fresh()->personal_access_token_id);
    }
}

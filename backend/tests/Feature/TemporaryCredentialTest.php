<?php

namespace Tests\Feature;

use App\Actions\Accounts\ActivateAccount;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeactivateAccount;
use App\Actions\Accounts\ResetAccountPassword;
use App\Actions\Accounts\RevealTemporaryCredential;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\PointRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class TemporaryCredentialTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private function admin(): User
    {
        return User::create(['role' => 'admin', 'name' => 'Synthetic administrator', 'password_hash' => 'Synthetic admin password phrase', 'account_status' => 'active', 'must_change_password' => false]);
    }

    private function createStudent(User $admin): User
    {
        return app(CreateAccount::class)->execute($admin, ['role' => 'student', 'name' => 'Synthetic student', 'nisn' => '0012345678', 'account_status' => 'active', 'password_hash' => 'injected secret']);
    }

    public function test_creation_hashes_and_encrypts_random_temporary_password_and_whitelists_attributes(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $user = $this->createStudent($admin);
        self::assertSame('pending_activation', $user->account_status);
        self::assertTrue($user->must_change_password);
        self::assertSame('0012345678', $user->student->nisn);
        self::assertSame('0012345678', $user->loginIdentifiers()->first()->normalized_value);
        $credential = $user->temporaryCredentials()->first();
        self::assertTrue(Hash::check($credential->encrypted_secret, $user->password_hash));
        self::assertNotSame($credential->encrypted_secret, DB::table('temporary_credentials')->value('encrypted_secret'));
        self::assertTrue($credential->expires_at->equalTo(now()->addDay()));
        self::assertStringNotContainsString($credential->encrypted_secret, AuditLog::all()->toJson());
        self::assertStringNotContainsString($user->password_hash, AuditLog::all()->toJson());
    }

    public function test_teacher_nip_is_optional_but_cross_alias_collisions_are_rejected(): void
    {
        $admin = $this->admin();
        $teacher = app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Synthetic teacher', 'teacher_code' => '00077']);
        self::assertNull($teacher->teacher->nip);
        $this->expectException(ValidationException::class);
        app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Another synthetic teacher', 'teacher_code' => 'G002', 'nip' => '00077']);
    }

    public function test_creation_to_login_preserves_leading_zeros_and_normalizes_teacher_code(): void
    {
        $admin = $this->admin();
        $teacher = app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Synthetic teacher', 'teacher_code' => 'GCase', 'nip' => '00077']);
        $password = $teacher->temporaryCredentials()->first()->encrypted_secret;
        foreach (['00077', 'GCASE', 'gcase'] as $identifier) {
            $this->postJson('/api/v1/auth/teacher/login', ['identifier' => $identifier, 'password' => $password])->assertOk()->assertJsonPath('data.id', $teacher->id);
        }
        self::assertSame('00077', $teacher->teacher->nip);
    }

    public function test_long_passphrases_are_accepted_and_suffixes_remain_significant(): void
    {
        $admin = $this->admin();
        $user = $this->createStudent($admin);
        $phrase = str_repeat('synthetic phrase ', 6).'one';
        $user = app(ActivateAccount::class)->execute($user, $phrase);
        self::assertTrue(Hash::check($phrase, $user->password_hash));
        self::assertFalse(Hash::check(str_repeat('synthetic phrase ', 6).'two', $user->password_hash));
        $unicode = str_repeat('é', 40).'synthetic phrase';
        $user = app(ActivateAccount::class)->execute($user, $unicode);
        self::assertTrue(Hash::check($unicode, $user->password_hash));
        self::assertFalse(Hash::check(str_repeat('é', 40).'changed phrase', $user->password_hash));
    }

    public function test_admin_reset_can_be_activated_but_last_active_admin_reset_is_rejected(): void
    {
        $admin = $this->admin();
        $other = app(CreateAccount::class)->execute($admin, ['role' => 'admin', 'name' => 'Synthetic second admin', 'username' => 'Admin002']);
        $other = app(ActivateAccount::class)->execute($other, 'Synthetic new admin phrase');
        app(ResetAccountPassword::class)->execute($admin, $other, 'Verified synthetic admin identity');
        self::assertSame('pending_activation', $other->fresh()->account_status);
        $activated = app(ActivateAccount::class)->execute($other->fresh(), 'Synthetic changed admin phrase');
        self::assertFalse($activated->must_change_password);
        self::assertSame('active', $activated->account_status);
    }

    public function test_create_admin_command_uses_hidden_password_and_does_not_output_credentials(): void
    {
        $this->artisan('civora:create-admin', ['username' => 'SyntheticAdmin', '--name' => 'Synthetic bootstrap admin'])
            ->expectsQuestion('Password admin (minimal 12 karakter)', 'Synthetic bootstrap admin phrase')
            ->expectsQuestion('Ulangi password admin', 'Synthetic bootstrap admin phrase')
            ->expectsOutput('Admin berhasil dibuat.')
            ->assertSuccessful();
        $admin = User::where('role', 'admin')->firstOrFail();
        self::assertTrue(Hash::check('Synthetic bootstrap admin phrase', $admin->password_hash));
        self::assertSame('active', $admin->account_status);
        self::assertFalse($admin->must_change_password);
        self::assertSame('syntheticadmin', $admin->loginIdentifiers()->first()->normalized_value);
        $this->assertDatabaseCount('temporary_credentials', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_nonadmin_cannot_create_reset_or_deactivate_accounts(): void
    {
        $teacher = User::create(['role' => 'teacher', 'name' => 'Synthetic teacher', 'password_hash' => 'Synthetic teacher phrase', 'account_status' => 'active', 'must_change_password' => false]);
        $this->expectException(AuthorizationException::class);
        app(CreateAccount::class)->execute($teacher, ['role' => 'student', 'name' => 'Synthetic student', 'nisn' => '00123']);
    }

    public function test_teacher_cannot_reset_or_deactivate_active_student(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);
        $student = app(ActivateAccount::class)->execute($student, 'Synthetic private student phrase');
        $teacher = app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Synthetic teacher', 'teacher_code' => 'G001']);
        $teacher = app(ActivateAccount::class)->execute($teacher, 'Synthetic private teacher phrase');
        foreach ([ResetAccountPassword::class, DeactivateAccount::class] as $action) {
            try {
                app($action)->execute($teacher, $student, 'Synthetic verified reason');
                self::fail('Teacher must not mutate student account');
            } catch (AuthorizationException $e) {
                self::assertSame('active', $student->fresh()->account_status);
            }
        }
    }

    public function test_pending_teacher_and_student_actor_cannot_reveal_credentials(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);
        $teacher = app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Synthetic teacher', 'teacher_code' => 'G001']);
        $url = '/api/v1/teacher/students/'.$student->student->id.'/temporary-credential/reveal';
        foreach ([$teacher, $student] as $actor) {
            $this->app['auth']->forgetGuards();
            $this->withToken($actor->createToken('synthetic')->plainTextToken)->postJson($url, ['current_password' => 'synthetic unused phrase'])->assertForbidden();
        }
        self::assertSame(0, AuditLog::where('action', 'temporary_credential_revealed')->count());
    }

    public function test_activation_token_cannot_bypass_temporary_expiration(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $user = $this->createStudent($admin);
        $secret = $user->temporaryCredentials()->first()->encrypted_secret;
        $token = $user->createToken('synthetic activation');
        $this->travel(24)->hours();
        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/change-password', ['current_password' => $secret, 'password' => 'Synthetic replacement phrase', 'password_confirmation' => 'Synthetic replacement phrase'])->assertUnauthorized();
        self::assertTrue($user->fresh()->must_change_password);
    }

    public function test_create_admin_command_refuses_mismatch_and_duplicate_alias(): void
    {
        $this->artisan('civora:create-admin', ['username' => 'SyntheticAdmin'])
            ->expectsQuestion('Password admin (minimal 12 karakter)', 'Synthetic bootstrap admin phrase')
            ->expectsQuestion('Ulangi password admin', 'Different synthetic phrase')
            ->expectsOutput('Admin tidak dibuat. Periksa username dan konfirmasi password (minimal 12 karakter).')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->artisan('civora:create-admin', ['username' => 'SyntheticAdmin'])
            ->expectsQuestion('Password admin (minimal 12 karakter)', 'Synthetic bootstrap admin phrase')
            ->expectsQuestion('Ulangi password admin', 'Synthetic bootstrap admin phrase')->expectsOutput('Admin berhasil dibuat.')->assertSuccessful();
        $this->artisan('civora:create-admin', ['username' => 'SYNTHETICADMIN'])
            ->expectsQuestion('Password admin (minimal 12 karakter)', 'Synthetic bootstrap admin phrase')
            ->expectsQuestion('Ulangi password admin', 'Synthetic bootstrap admin phrase')
            ->expectsOutput('Admin tidak dibuat. Periksa username dan konfirmasi password (minimal 12 karakter).')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_teacher_reveal_requires_reauthentication_and_cannot_reveal_active_password(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);
        $teacher = app(CreateAccount::class)->execute($admin, ['role' => 'teacher', 'name' => 'Synthetic teacher', 'teacher_code' => 'G001']);
        $teacher->update(['account_status' => 'active', 'must_change_password' => false, 'password_hash' => 'Synthetic teacher password phrase']);
        $token = $teacher->createToken('synthetic');
        $url = '/api/v1/teacher/students/'.$student->student->id.'/temporary-credential/reveal';
        $this->withToken($token->plainTextToken)->postJson($url, ['current_password' => 'wrong phrase'])->assertUnprocessable();
        $response = $this->withToken($token->plainTextToken)->postJson($url, ['current_password' => 'Synthetic teacher password phrase'])->assertOk();
        self::assertSame($student->temporaryCredentials()->first()->encrypted_secret, $response->json('data.password'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(1, AuditLog::where('action', 'temporary_credential_revealed')->count());
        $student->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->withToken($token->plainTextToken)->postJson($url, ['current_password' => 'Synthetic teacher password phrase'])->assertNotFound();
    }

    public function test_expired_temporary_credential_cannot_be_revealed(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);
        $this->travel(24)->hours();
        $this->expectException(GoneHttpException::class);
        app(RevealTemporaryCredential::class)->execute($admin, $student->student);
    }

    public function test_reset_revokes_all_tokens_web_sessions_push_qr_and_pending_selections(): void
    {
        $this->fixture();
        $admin = User::findOrFail($this->rows['users']['id']);
        $admin->update(['must_change_password' => false]);
        $student = Student::findOrFail($this->rows['students']['id']);
        $user = $student->user;
        $old = $user->createToken('synthetic old');
        DB::table('sessions')->insert(['id' => 'synthetic web session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        app(ResetAccountPassword::class)->execute($admin, $user, 'Verified identity at school');
        self::assertSame('pending_activation', $user->fresh()->account_status);
        self::assertTrue($user->fresh()->must_change_password);
        self::assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'active' => false]);
        self::assertNotNull($student->qrTokens()->first()->revoked_at);
        self::assertTrue($student->studentSelections()->first()->expires_at->lessThanOrEqualTo(now()));
        $this->withToken($old->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        self::assertSame(1, $user->temporaryCredentials()->count());
    }

    public function test_last_active_admin_cannot_be_deactivated_or_reset(): void
    {
        $admin = $this->admin();
        foreach ([DeactivateAccount::class, ResetAccountPassword::class] as $action) {
            try {
                app($action)->execute($admin, $admin, 'Synthetic verified reason');
                self::fail('Last active admin must remain active');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('account', $e->errors());
            }
        }
        self::assertSame('active', $admin->fresh()->account_status);
    }

    public function test_deactivation_revokes_sessions_and_removes_temporary_password(): void
    {
        $admin = $this->admin();
        $user = $this->createStudent($admin);
        $token = $user->createToken('synthetic old');
        app(DeactivateAccount::class)->execute($admin, $user, 'Synthetic school reason');
        self::assertSame('inactive', $user->fresh()->account_status);
        $this->assertDatabaseCount('temporary_credentials', 0);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_resource_policies_keep_student_identity_and_archived_authored_records_private(): void
    {
        $this->fixture();
        User::query()->update(['must_change_password' => false]);
        $student = Student::findOrFail($this->rows['students']['id']);
        $record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $attachment = Attachment::findOrFail($this->rows['attachments']['id']);
        $teacher = $record->teacher->user;
        $other = User::create(['role' => 'teacher', 'name' => 'Other synthetic teacher', 'password_hash' => 'Synthetic teacher phrase', 'account_status' => 'active', 'must_change_password' => false]);
        self::assertTrue(Gate::forUser($teacher)->allows('view', $student));
        self::assertTrue(Gate::forUser($other)->allows('view', $record));
        $student->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        self::assertTrue(Gate::forUser($teacher)->allows('view', $student->fresh()));
        self::assertTrue(Gate::forUser($teacher)->allows('recordPoints', $student->fresh()));
        $student->update(['education_status' => 'transferred']);
        $student->user->update(['account_status' => 'inactive']);
        self::assertFalse(Gate::forUser($other)->allows('view', $student->fresh()));
        self::assertFalse(Gate::forUser($other)->allows('view', $record->fresh()));
        self::assertTrue(Gate::forUser($teacher)->allows('view', $record->fresh()));
        self::assertTrue(Gate::forUser($teacher)->allows('requestCorrection', $record->fresh()));
        self::assertTrue(Gate::forUser($teacher)->allows('view', $attachment));
        self::assertFalse(Gate::forUser($other)->allows('view', $attachment));
        $teacher->update(['must_change_password' => true]);
        self::assertFalse(Gate::forUser($teacher)->allows('view', $record->fresh()));
    }
}

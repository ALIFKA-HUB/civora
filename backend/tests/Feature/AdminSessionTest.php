<?php

namespace Tests\Feature;

use App\Actions\Accounts\DeactivateAccount;
use App\Livewire\Admin\Activate;
use App\Livewire\Admin\ConfirmPassword;
use App\Livewire\Admin\Login;
use App\Models\LoginIdentifier;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $username, string $status = 'active', bool $mustChangePassword = false): User
    {
        $user = User::create([
            'role' => $role,
            'name' => 'Synthetic '.ucfirst($role),
            'password_hash' => 'Synthetic admin passphrase',
            'account_status' => $status,
            'must_change_password' => $mustChangePassword,
        ]);
        LoginIdentifier::create([
            'user_id' => $user->id,
            'role' => $role,
            'kind' => 'username',
            'normalized_value' => strtolower($username),
        ]);
        if ($status === 'pending_activation') {
            TemporaryCredential::create([
                'user_id' => $user->id,
                'encrypted_secret' => 'Synthetic admin passphrase',
                'expires_at' => now()->addDay(),
                'generated_by' => $user->id,
                'reason' => 'Synthetic initial admin activation',
            ]);
        }

        return $user;
    }

    public function test_only_an_active_admin_can_open_the_admin_dashboard(): void
    {
        $teacher = $this->account('teacher', 'teacher01');
        $student = $this->account('student', 'student01');

        self::assertFalse(Gate::forUser($teacher)->allows('access-admin'));
        $this->actingAs($teacher)->get('/admin')->assertForbidden();
        $this->actingAs($student)->get('/admin')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_login_redirects_without_echoing_password(): void
    {
        $this->account('admin', 'admin01');

        Livewire::test(Login::class)
            ->set('username', 'ADMIN01')
            ->set('password', 'Synthetic admin passphrase')
            ->call('authenticate')
            ->assertRedirect('/admin')
            ->assertSet('password', '');
    }

    public function test_pending_admin_can_only_view_identity_activate_and_logout(): void
    {
        $pending = $this->account('admin', 'admin01', 'pending_activation', true);

        $this->actingAs($pending)->get('/admin')->assertRedirect('/admin/activate');
        $this->actingAs($pending)->get('/admin/activate')->assertOk()->assertSee('Synthetic Admin');
        $this->actingAs($pending);
        Livewire::test(Activate::class)
            ->assertSee('Synthetic Admin')
            ->set('password', 'A new secure synthetic passphrase')
            ->set('password_confirmation', 'A new secure synthetic passphrase')
            ->call('activate')
            ->assertRedirect('/admin/login')
            ->assertSet('password', '');
        self::assertSame('active', $pending->fresh()->account_status);
        self::assertFalse($pending->fresh()->must_change_password);
    }

    public function test_admin_session_expires_after_thirty_minutes_idle(): void
    {
        $admin = $this->account('admin', 'admin01');

        $this->actingAs($admin)->withSession(['admin_last_activity' => now()->subMinutes(30)->timestamp])
            ->get('/admin')->assertRedirect('/admin/login');
        self::assertGuest('web');
    }

    public function test_admin_mutating_routes_keep_web_csrf_protection(): void
    {
        $admin = $this->account('admin', 'admin01');
        $this->app['env'] = 'local';

        $this->actingAs($admin)->post('/admin/logout')->assertStatus(419);
    }

    public function test_admin_mutations_require_recent_password_confirmation(): void
    {
        $admin = $this->account('admin', 'admin01');
        Route::middleware(['web', 'auth:web', 'admin.reauthenticated'])
            ->get('/admin/test-mutation', fn () => 'confirmed')
            ->name('admin.test-mutation');

        $this->actingAs($admin)->get('/admin/test-mutation')->assertRedirect('/admin/confirm-password');
        Livewire::test(ConfirmPassword::class)
            ->set('password', 'wrong synthetic passphrase')
            ->call('confirm')
            ->assertHasErrors('password')
            ->assertSet('password', '');
        Livewire::test(ConfirmPassword::class)
            ->set('password', 'Synthetic admin passphrase')
            ->call('confirm')
            ->assertRedirect('/admin/test-mutation');
        $this->get('/admin/test-mutation')->assertOk()->assertSeeText('confirmed');
    }

    public function test_existing_last_admin_protection_still_blocks_deactivation(): void
    {
        $admin = $this->account('admin', 'admin01');

        try {
            app(DeactivateAccount::class)->execute($admin, $admin, 'A valid reason for deactivation');
            self::fail('The active administrator must not deactivate the last active administrator.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('account', $exception->errors());
        }

        self::assertSame('active', $admin->fresh()->account_status);
    }
}

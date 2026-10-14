<?php

namespace Tests\Feature;

use App\Actions\Accounts\DeactivateAccount;
use App\Livewire\Admin\Activate;
use App\Livewire\Admin\ConfirmPassword;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Models\LoginIdentifier;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
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

    public function test_livewire_dashboard_update_expires_an_idle_admin_session(): void
    {
        $admin = $this->account('admin', 'admin01');
        $response = $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Dashboard');
        $snapshot = $this->extractLivewireSnapshot($response->getContent(), '/admin');

        $this->withSession(['admin_last_activity' => now()->subMinutes(30)->timestamp]);

        $update = $this->updateLivewireComponent($snapshot);
        self::assertSame(302, $update->status(), $update->getContent());
        $update->assertRedirect('/admin/login');
        self::assertGuest('web');
    }

    public function test_livewire_dashboard_update_rejects_an_admin_deactivated_after_page_load(): void
    {
        $admin = $this->account('admin', 'admin01');
        auth('web')->login($admin);
        $response = $this->get('/admin')->assertOk()->assertSee('Dashboard');
        $snapshot = $this->extractLivewireSnapshot($response->getContent(), '/admin');

        $admin->update(['account_status' => 'inactive']);
        $this->app['auth']->forgetGuards();

        $update = $this->updateLivewireComponent($snapshot);
        self::assertSame(302, $update->status(), $update->getContent());
        $update->assertRedirect('/admin/login');
        self::assertGuest('web');
    }

    public function test_livewire_activation_update_rechecks_pending_activation_credentials(): void
    {
        $pending = $this->account('admin', 'pending01', 'pending_activation', true);
        $response = $this->actingAs($pending)->get('/admin/activate')->assertOk();
        $snapshot = $this->extractLivewireSnapshot($response->getContent(), '/admin/activate');
        $pending->temporaryCredentials()->update(['expires_at' => now()->subSecond()]);

        $this->updateLivewireComponent($snapshot)->assertUnauthorized();
        self::assertSame('pending_activation', $pending->fresh()->account_status);
    }

    public function test_livewire_sensitive_route_update_rechecks_recent_password_confirmation(): void
    {
        $admin = $this->account('admin', 'admin01');
        Route::get('/admin/test-sensitive-livewire', Dashboard::class)
            ->middleware(['web', 'auth:web', 'admin.session', 'admin.active', 'admin.reauthenticated']);
        $response = $this->actingAs($admin)
            ->withSession(['admin_password_confirmed_at' => now()->timestamp])
            ->get('/admin/test-sensitive-livewire')
            ->assertOk();
        $snapshot = $this->extractLivewireSnapshot($response->getContent(), '/admin/test-sensitive-livewire');

        $this->withSession(['admin_password_confirmed_at' => now()->subMinutes(15)->timestamp]);

        $this->updateLivewireComponent($snapshot)->assertRedirect('/admin/confirm-password');
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

    private function extractLivewireSnapshot(string $html, string $expectedPath): string
    {
        self::assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ltrim($expectedPath, '/'), $decoded['memo']['path']);
        self::assertSame('GET', $decoded['memo']['method']);

        return $snapshot;
    }

    private function updateLivewireComponent(string $snapshot, array $updates = [], array $calls = []): TestResponse
    {
        $updateRoute = '/'.ltrim(app('router')->getRoutes()->getByName('default-livewire.update')->uri(), '/');

        return $this->postJson($updateRoute, [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => $calls,
            ]],
        ], ['X-Livewire' => 'true']);
    }
}

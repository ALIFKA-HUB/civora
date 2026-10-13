<?php

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountSecurity;
use App\Models\LoginIdentifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Login extends Component
{
    public string $username = '';

    public string $password = '';

    public function authenticate(): void
    {
        $this->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $password = $this->password;
        $this->password = '';
        $key = 'admin-login:'.hash('sha256', request()->ip().'|'.mb_strtolower(trim($this->username)));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan. Silakan coba lagi nanti.']);
        }

        $identifier = LoginIdentifier::query()->where('role', 'admin')->where('kind', 'username')
            ->where('normalized_value', mb_strtolower(trim($this->username)))->first();
        $user = $identifier?->user;

        if (! $user || ! Hash::check($password, $user->password_hash)
            || $user->account_status === 'inactive'
            || ($user->account_status === 'active' && ! AccountSecurity::active($user))
            || ($user->account_status === 'pending_activation' && ! $user->temporaryCredentials()
                ->whereNull('consumed_at')->where('expires_at', '>', now())->exists())) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Username atau password tidak sesuai.']);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user);
        session()->regenerate();
        session()->forget('admin_password_confirmed_at');
        session()->put('admin_last_activity', now()->timestamp);

        $this->redirectRoute($user->account_status === 'pending_activation' || $user->must_change_password
            ? 'admin.activate'
            : 'admin.home');
    }

    public function render()
    {
        return view('livewire.admin.login', ['title' => 'Masuk Admin']);
    }
}

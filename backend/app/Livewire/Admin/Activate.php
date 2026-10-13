<?php

namespace App\Livewire\Admin;

use App\Actions\Accounts\ActivateAccount;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Activate extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public function activate(ActivateAccount $action): void
    {
        $user = auth('web')->user();
        abort_unless($user?->role === 'admin'
            && ($user->account_status === 'pending_activation' || $user->must_change_password), 403);

        $this->validate([
            'password' => ['required', 'string', 'min:12', 'max:1024', 'same:password_confirmation'],
        ], ['password.same' => 'Konfirmasi password tidak sama.']);

        $newPassword = $this->password;
        $this->reset('password', 'password_confirmation');
        $action->execute($user, $newPassword);
        auth('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('admin.login');
    }

    public function render()
    {
        return view('livewire.admin.activate', ['title' => 'Aktivasi Admin']);
    }
}

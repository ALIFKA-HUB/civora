<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class ConfirmPassword extends Component
{
    public string $password = '';

    public function confirm(): void
    {
        $this->validate(['password' => ['required', 'string', 'max:1024']]);

        $password = $this->password;
        $this->password = '';

        if (! Hash::check($password, auth('web')->user()->password_hash)) {
            throw ValidationException::withMessages(['password' => 'Password tidak sesuai.']);
        }

        session()->put('admin_password_confirmed_at', now()->timestamp);
        $this->redirect(session()->pull('url.intended', route('admin.home')));
    }

    public function render()
    {
        return view('livewire.admin.confirm-password', ['title' => 'Konfirmasi Password']);
    }
}

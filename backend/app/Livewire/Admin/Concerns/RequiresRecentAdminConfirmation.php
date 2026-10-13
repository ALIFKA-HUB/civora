<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Validation\ValidationException;

trait RequiresRecentAdminConfirmation
{
    protected function requireRecentAdminConfirmation(): void
    {
        $confirmedAt = session('admin_password_confirmed_at');
        $age = is_numeric($confirmedAt) ? now()->timestamp - (int) $confirmedAt : null;
        if ($age === null || $age < 0 || $age >= 15 * 60) {
            $previousUrl = session('_previous.url');
            if (is_string($previousUrl) && str_starts_with($previousUrl, url('/admin/'))) {
                session()->put('url.intended', $previousUrl);
            }
            throw ValidationException::withMessages([
                'reauthentication' => 'Konfirmasikan password admin terbaru sebelum melanjutkan.',
            ]);
        }
    }
}

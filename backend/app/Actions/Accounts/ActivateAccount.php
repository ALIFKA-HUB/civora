<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ActivateAccount
{
    public function execute(User $user, string $newPassword): User
    {
        Validator::make(['password' => $newPassword], ['password' => ['required', 'string', 'min:12', 'max:1024']], ['min' => 'Password minimal 12 karakter.', 'max' => 'Password terlalu panjang.', 'required' => 'Password wajib diisi.'])->validate();

        return DB::transaction(function () use ($user, $newPassword): User {
            if ($user->role === 'admin') {
                AccountSecurity::lockAdminChanges();
            }
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->account_status === 'inactive', 401, 'Sesi tidak sah.');
            if ($user->must_change_password || $user->account_status === 'pending_activation') {
                abort_unless($user->temporaryCredentials()->whereNull('consumed_at')->where('expires_at', '>', now())->exists(), 401, 'Sesi tidak sah.');
            }
            AccountSecurity::revoke($user);
            $user->update(['password_hash' => $newPassword, 'account_status' => 'active', 'must_change_password' => false, 'remember_token' => null]);
            $user->temporaryCredentials()->delete();
            AccountSecurity::audit($user, $user, 'account_password_changed');

            return $user;
        });
    }
}

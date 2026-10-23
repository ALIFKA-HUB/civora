<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateAccount
{
    public function execute(User $admin, User $target, string $reason): void
    {
        $reason = AccountSecurity::reason($reason);
        DB::transaction(function () use ($admin, $target, $reason): void {
            AccountSecurity::lockAdminChanges();
            [$admin, $target] = AccountSecurity::lockPair($admin, $target);
            AccountSecurity::admin($admin);
            AccountSecurity::protectLastAdmin($target);
            AccountSecurity::revoke($target);
            $target->update(['account_status' => 'inactive', 'remember_token' => null]);
            $target->temporaryCredentials()->delete();
            AccountSecurity::audit($admin, $target, 'account_deactivated', $reason);
        });
    }
}

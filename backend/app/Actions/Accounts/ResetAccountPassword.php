<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetAccountPassword
{
    public function execute(User $admin, User $target, string $reason): void
    {
        $reason = AccountSecurity::reason($reason);
        DB::transaction(function () use ($admin, $target, $reason): void {
            AccountSecurity::lockAdminChanges();
            [$admin, $target] = AccountSecurity::lockPair($admin, $target);
            AccountSecurity::admin($admin);
            AccountSecurity::protectLastAdmin($target);
            abort_if($target->account_status === 'inactive' || ($target->student && $target->student->education_status !== 'active'), 403, 'Akun tidak aktif tidak dapat direset.');
            AccountSecurity::revoke($target);
            AccountSecurity::temporary($admin, $target, $reason);
            AccountSecurity::audit($admin, $target, 'account_password_reset', $reason);
        });
    }
}

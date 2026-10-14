<?php

namespace App\Actions\Catalog;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\PointType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeactivatePointType
{
    public function execute(User $admin, PointType $type): void
    {
        DB::transaction(function () use ($admin, $type): void {
            $type = PointType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            if (! $type->active) {
                return;
            }

            $type->update(['active' => false]);
            AuditLog::create([
                'actor_user_id' => $admin->id,
                'action' => 'point_type_deactivated',
                'resource_type' => 'point_type',
                'resource_id' => $type->id,
                'safe_before' => ['active' => true],
                'safe_after' => ['active' => false],
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);
        }, 3);
    }
}

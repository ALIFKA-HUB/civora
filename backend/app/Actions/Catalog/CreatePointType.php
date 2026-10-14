<?php

namespace App\Actions\Catalog;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\PointType;
use App\Models\PointTypeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreatePointType
{
    public function execute(User $admin, array $attributes): PointType
    {
        return DB::transaction(function () use ($admin, $attributes): PointType {
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            $this->whitelist($attributes, ['code', 'category', 'name', 'points', 'description']);
            foreach (['code', 'name', 'description'] as $field) {
                if (isset($attributes[$field]) && is_string($attributes[$field])) {
                    $attributes[$field] = trim($attributes[$field]);
                }
            }
            $data = Validator::make($attributes, [
                'code' => ['required', 'string', 'max:50', 'unique:point_types,code'],
                'category' => ['required', 'in:achievement,violation'],
                'name' => ['required', 'string', 'max:255'],
                'points' => ['required', 'integer', 'min:1'],
                'description' => ['required', 'string', 'max:1000'],
            ])->validate();

            $type = PointType::create(['code' => $data['code'], 'category' => $data['category'], 'active' => true]);
            $version = $type->pointTypeVersions()->create([
                'version' => 1,
                'name' => $data['name'],
                'description' => $data['description'],
                'points' => $data['points'],
                'created_by' => $admin->id,
            ]);
            $this->audit($admin, $type, 'point_type_created', null, $this->snapshot($type, $version));

            return $type;
        }, 3);
    }

    private function whitelist(array $attributes, array $allowed): void
    {
        $unknown = array_diff(array_keys($attributes), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([array_values($unknown)[0] => 'Field tidak diizinkan.']);
        }
    }

    private function snapshot(PointType $type, PointTypeVersion $version): array
    {
        return ['code' => $type->code, 'category' => $type->category, 'active' => $type->active, 'version' => $version->version, 'name' => $version->name, 'points' => $version->points, 'description' => $version->description];
    }

    private function audit(User $admin, PointType $type, string $action, ?array $before, array $after): void
    {
        AuditLog::create(['actor_user_id' => $admin->id, 'action' => $action, 'resource_type' => 'point_type', 'resource_id' => $type->id, 'safe_before' => $before, 'safe_after' => $after, 'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid()]);
    }
}

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

final class RevisePointType
{
    public function execute(User $admin, PointType $type, array $attributes): PointTypeVersion
    {
        return DB::transaction(function () use ($admin, $type, $attributes): PointTypeVersion {
            $type = PointType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            $unknown = array_diff(array_keys($attributes), ['category', 'name', 'points', 'description']);
            if ($unknown !== []) {
                throw ValidationException::withMessages([array_values($unknown)[0] => 'Field tidak diizinkan.']);
            }
            foreach (['name', 'description'] as $field) {
                if (isset($attributes[$field]) && is_string($attributes[$field])) {
                    $attributes[$field] = trim($attributes[$field]);
                }
            }
            $data = Validator::make($attributes, [
                'category' => ['sometimes', 'required', 'in:achievement,violation'],
                'name' => ['required', 'string', 'max:255'],
                'points' => ['required', 'integer', 'min:1'],
                'description' => ['required', 'string', 'max:1000'],
            ])->validate();

            $beforeCategory = $type->category;
            $category = $data['category'] ?? $beforeCategory;
            if ($category !== $type->category) {
                $used = DB::table('record_revisions')
                    ->join('point_type_versions', 'point_type_versions.id', '=', 'record_revisions.point_type_version_id')
                    ->where('point_type_versions.point_type_id', $type->id)
                    ->exists();
                if ($used) {
                    throw ValidationException::withMessages(['category' => 'Kategori tidak dapat diubah setelah jenis digunakan.']);
                }
                $type->update(['category' => $category]);
            }

            $latest = $type->pointTypeVersions()->orderByDesc('version')->firstOrFail();
            $version = $type->pointTypeVersions()->create([
                'version' => $latest->version + 1,
                'name' => $data['name'],
                'description' => $data['description'],
                'points' => $data['points'],
                'created_by' => $admin->id,
            ]);
            AuditLog::create([
                'actor_user_id' => $admin->id,
                'action' => 'point_type_revised',
                'resource_type' => 'point_type',
                'resource_id' => $type->id,
                'safe_before' => ['category' => $beforeCategory, 'version' => $latest->version, 'name' => $latest->name, 'points' => $latest->points, 'description' => $latest->description],
                'safe_after' => ['category' => $type->category, 'version' => $version->version, 'name' => $version->name, 'points' => $version->points, 'description' => $version->description],
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);

            return $version;
        }, 3);
    }
}

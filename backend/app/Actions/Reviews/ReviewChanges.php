<?php

namespace App\Actions\Reviews;

use App\Models\Attachment;
use App\Models\PointRecord;
use App\Models\PointType;
use App\Models\PointTypeVersion;
use App\Models\RecordRevision;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviewChanges
{
    public static function validate(array $changes, bool $requireRevisionValue = true): array
    {
        if (array_diff(array_keys($changes), ['operation', 'point_type_version_id', 'description', 'attachment_id']) !== []) {
            throw ValidationException::withMessages(['changes' => 'Field perubahan identitas atau field lain tidak diizinkan.']);
        }

        $data = Validator::make($changes, [
            'operation' => ['required', 'in:cancel,revise'],
            'point_type_version_id' => ['sometimes', 'required', 'uuid'],
            'description' => ['sometimes', 'required', 'string', 'min:5', 'max:1000'],
            'attachment_id' => ['sometimes', 'nullable', 'uuid'],
        ])->validate();

        if ($data['operation'] === 'cancel' && count($data) !== 1) {
            throw ValidationException::withMessages(['changes' => 'Pembatalan tidak menerima perubahan isi catatan.']);
        }
        if ($data['operation'] === 'revise' && $requireRevisionValue && count($data) < 2) {
            throw ValidationException::withMessages(['changes' => 'Koreksi harus mengubah setidaknya satu nilai catatan.']);
        }

        return $data;
    }

    public static function append(User $admin, PointRecord $record, RecordRevision $current, string $reason, array $input): RecordRevision
    {
        $changes = self::validate($input);
        if ($current->revision_number !== (int) $record->revisions()->max('revision_number')) {
            self::conflict('RECORD_REVISION_CONFLICT', 'Catatan berubah. Muat ulang sebelum memutuskan.');
        }

        $snapshot = [
            'point_type_version_id' => $current->point_type_version_id,
            'points_snapshot' => $current->points_snapshot,
            'name_snapshot' => $current->name_snapshot,
            'category_snapshot' => $current->category_snapshot,
            'description' => $current->description,
            'attachment_id' => $current->attachment_id,
            'status' => $changes['operation'] === 'cancel' ? 'cancelled' : 'active',
        ];

        if ($changes['operation'] === 'revise') {
            if (array_key_exists('description', $changes)) {
                $snapshot['description'] = trim($changes['description']);
                if (mb_strlen($snapshot['description']) < 5) {
                    throw ValidationException::withMessages(['description' => 'Keterangan harus berisi 5–1.000 karakter.']);
                }
            }
            if (array_key_exists('point_type_version_id', $changes)) {
                $version = PointTypeVersion::whereKey($changes['point_type_version_id'])->lockForUpdate()->first();
                $type = $version ? PointType::whereKey($version->point_type_id)->lockForUpdate()->first() : null;
                $latestVersion = $type ? PointTypeVersion::where('point_type_id', $type->id)->orderByDesc('version')->value('id') : null;
                if (! $version || ! $type?->active || $latestVersion !== $version->id) {
                    self::conflict('POINT_TYPE_CHANGED', 'Versi jenis poin tidak lagi tersedia. Muat ulang daftar poin.');
                }
                $snapshot['point_type_version_id'] = $version->id;
                $snapshot['points_snapshot'] = $version->points;
                $snapshot['name_snapshot'] = $version->name;
                $snapshot['category_snapshot'] = $type->category;
            }
            if (array_key_exists('attachment_id', $changes)) {
                if ($changes['attachment_id'] !== null && ! Attachment::whereKey($changes['attachment_id'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['attachment_id' => 'Bukti tidak tersedia.']);
                }
                $snapshot['attachment_id'] = $changes['attachment_id'];
            }
        }

        return $record->revisions()->create([
            'revision_number' => $current->revision_number + 1,
            ...$snapshot,
            'changed_by' => $admin->id,
            'reason' => $reason,
        ]);
    }

    public static function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => $code, 'message' => $message],
            'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
        ], 409));
    }
}

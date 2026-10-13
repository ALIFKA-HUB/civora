<?php

namespace App\Actions\Academic;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlacementRules
{
    public static function student(Student $student): void
    {
        if ($student->education_status !== 'active') {
            throw ValidationException::withMessages(['student' => 'Siswa tidak aktif dalam pendidikan.']);
        }
    }

    public static function classroom(string $id, Semester $semester): Classroom
    {
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages(['classroom' => 'Identitas kelas tidak valid.']);
        }
        $classroom = Classroom::whereKey($id)->lockForUpdate()->first();
        if (! $classroom || ! $classroom->active || $classroom->academic_year_id !== $semester->academic_year_id
            || ! $classroom->major()->lockForUpdate()->firstOrFail()->active) {
            throw ValidationException::withMessages(['classroom' => 'Kelas/jurusan harus aktif dan sesuai tahun ajaran semester.']);
        }

        return $classroom;
    }

    public static function instant(Semester $semester, string $effectiveAt): CarbonImmutable
    {
        $calendar = new SchoolCalendar;
        $at = $calendar->instant($effectiveAt);
        if (! $calendar->contains($semester, $at)) {
            throw ValidationException::withMessages(['effective_at' => 'Waktu penempatan harus berada dalam semester.']);
        }

        return $at;
    }

    public static function audit(User $admin, string $type, string $id, string $action, array $before, array $after, ?string $reason = null): void
    {
        AuditLog::create(['actor_user_id' => $admin->id, 'action' => $action, 'resource_type' => $type, 'resource_id' => $id, 'safe_before' => $before, 'safe_after' => $after, 'reason' => $reason, 'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid()]);
    }
}

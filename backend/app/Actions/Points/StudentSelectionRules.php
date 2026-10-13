<?php

namespace App\Actions\Points;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\CurrentEnrollment;
use App\Services\Academic\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class StudentSelectionRules
{
    public static function teacher(User $user, bool $lock = false): User
    {
        $query = User::whereKey($user->id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $user || $user->role !== 'teacher' || ! AccountSecurity::active($user) || ! $user->teacher()->exists()) {
            throw new AuthorizationException('Akses ditolak.');
        }

        return $user;
    }

    public static function student(Student $student, bool $allowPendingAccount = true, bool $lockUser = false): Student
    {
        $student = Student::with('user')->find($student->id);
        if ($student && $lockUser) {
            $student->setRelation('user', User::whereKey($student->user_id)->lockForUpdate()->first());
        }
        if (! $student || $student->education_status !== 'active' || ! $student->user
            || $student->user->role !== 'student' || $student->user->account_status === 'inactive'
            || (! $allowPendingAccount && ! AccountSecurity::active($student->user))) {
            throw ValidationException::withMessages(['student' => 'Siswa tidak tersedia untuk dipilih.']);
        }

        return $student;
    }

    public static function activeSemester(): Semester
    {
        $semester = Semester::where('status', 'active')->lockForUpdate()->first();
        if (! $semester || ! (new SchoolCalendar)->contains($semester, CarbonImmutable::now())) {
            throw ValidationException::withMessages(['semester' => 'Tidak ada semester aktif untuk pencatatan.']);
        }

        return $semester;
    }

    public static function enrollment(Student $student, Semester $semester): Enrollment
    {
        $at = CarbonImmutable::now();
        $enrollment = (new CurrentEnrollment)->forStudent($student, $semester, $at->toIso8601String());
        if (! $enrollment) {
            throw ValidationException::withMessages(['student' => 'Siswa tidak memiliki penempatan aktif pada semester ini.']);
        }
        $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();
        $classroom = $enrollment?->classroom;
        $major = $classroom?->major;
        if (! $enrollment || ! $classroom || ! $classroom->active || ! $major || ! $major->active
            || $classroom->academic_year_id !== $semester->academic_year_id) {
            throw ValidationException::withMessages(['student' => 'Penempatan siswa sudah tidak aktif.']);
        }

        return $enrollment;
    }

    public static function issuingSessionIsValid(?int $sessionId, string $studentUserId): bool
    {
        $session = $sessionId ? PersonalAccessToken::whereKey($sessionId)->lockForUpdate()->first() : null;
        if (! $session || $session->tokenable_type !== User::class || $session->tokenable_id !== $studentUserId
            || ($session->expires_at && $session->expires_at->lessThanOrEqualTo(CarbonImmutable::now()))) {
            return false;
        }
        $expiration = config('sanctum.expiration');

        return ! $expiration || $session->created_at->addMinutes($expiration)->greaterThan(CarbonImmutable::now());
    }
}

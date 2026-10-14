<?php

namespace App\Actions\Academic;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\SchoolCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollStudent
{
    public function execute(User $admin, Student $student, Semester $semester, string $classroomId, string $effectiveAt): Enrollment
    {
        return DB::transaction(function () use ($admin, $student, $semester, $classroomId, $effectiveAt): Enrollment {
            $semester = Semester::whereKey($semester->id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            PlacementRules::student($student);
            if (! in_array($semester->status, ['draft', 'active'], true)
                || $semester->start_date->lessThan($semester->academicYear->start_date)
                || $semester->end_date->greaterThan($semester->academicYear->end_date)) {
                throw ValidationException::withMessages(['semester' => 'Penempatan hanya tersedia pada semester draft/aktif.']);
            }
            $classroom = PlacementRules::classroom($classroomId, $semester);
            $at = PlacementRules::instant($semester, $effectiveAt);
            $end = (new SchoolCalendar)->end($semester);
            if ($student->enrollments()->where('semester_id', $semester->id)->where('valid_from', '<', $end)
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $at))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['enrollment' => 'Penempatan siswa sudah ada dalam rentang tersebut.']);
            }
            $enrollment = Enrollment::create(['student_id' => $student->id, 'semester_id' => $semester->id, 'classroom_id' => $classroom->id, 'valid_from' => $at, 'valid_until' => $end]);
            PlacementRules::audit($admin, 'student', $student->id, 'student_enrolled', [], ['enrollment_id' => $enrollment->id, 'semester_id' => $semester->id, 'classroom_id' => $classroom->id]);

            return $enrollment;
        }, 3);
    }
}

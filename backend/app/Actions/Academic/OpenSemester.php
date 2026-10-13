<?php

namespace App\Actions\Academic;

use App\Actions\Accounts\AccountSecurity;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenSemester
{
    public function execute(User $admin, Semester $semester): Semester
    {
        return DB::transaction(function () use ($admin, $semester): Semester {
            $semesters = Semester::orderBy('id')->lockForUpdate()->get();
            $semester = $semesters->firstWhere('id', $semester->id) ?? Semester::findOrFail($semester->id);
            $students = Student::where('education_status', 'active')->orderBy('id')->lockForUpdate()->get();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            $calendar = new SchoolCalendar;
            $at = CarbonImmutable::now();
            if ($semester->status !== 'draft' || ! $calendar->contains($semester, $at)
                || $semester->start_date->lessThan($semester->academicYear->start_date)
                || $semester->end_date->greaterThan($semester->academicYear->end_date)
                || $semesters->contains(fn ($other) => $other->id !== $semester->id && $other->start_date->lessThanOrEqualTo($semester->end_date) && $other->end_date->greaterThanOrEqualTo($semester->start_date))) {
                throw ValidationException::withMessages(['semester' => 'Semester harus draft, tidak tumpang tindih, dan sesuai rentang tanggal.']);
            }
            $enrollments = Enrollment::where('semester_id', $semester->id)->whereIn('student_id', $students->modelKeys())->orderBy('id')->lockForUpdate()->get();
            foreach ($enrollments as $enrollment) {
                PlacementRules::classroom($enrollment->classroom_id, $semester);
                if ($enrollment->valid_from->lessThan($calendar->start($semester))
                    || ! $enrollment->valid_until || $enrollment->valid_until->greaterThan($calendar->end($semester))) {
                    throw ValidationException::withMessages(['enrollment' => 'Rentang penempatan harus berada dalam semester.']);
                }
            }
            foreach ($students as $student) {
                if (! $enrollments->contains(fn ($row) => $row->student_id === $student->id && $row->valid_from->lessThanOrEqualTo($at) && (! $row->valid_until || $row->valid_until->greaterThan($at)))) {
                    throw ValidationException::withMessages(['enrollment' => 'Penempatan setiap siswa aktif harus disiapkan.']);
                }
            }
            foreach ($semesters->where('status', 'active') as $old) {
                $old->update(['status' => 'closed']);
                PlacementRules::audit($admin, 'semester', $old->id, 'semester_closed', ['status' => 'active'], ['status' => 'closed']);
            }
            $semester->update(['status' => 'active']);
            PlacementRules::audit($admin, 'semester', $semester->id, 'semester_opened', ['status' => 'draft'], ['status' => 'active']);

            return $semester;
        }, 3);
    }
}

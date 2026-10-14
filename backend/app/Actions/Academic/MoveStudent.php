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

class MoveStudent
{
    public function execute(User $admin, Student $student, string $classroomId, string $effectiveAt): Enrollment
    {
        // Discover the ID before locking; recheck status after any semester switch.
        $semesterId = Semester::where('status', 'active')->value('id');

        return DB::transaction(function () use ($admin, $student, $semesterId, $classroomId, $effectiveAt): Enrollment {
            $semester = Semester::whereKey($semesterId)->lockForUpdate()->first();
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($admin);
            PlacementRules::student($student);
            if (! $semester || $semester->status !== 'active') {
                throw ValidationException::withMessages(['semester' => 'Semester aktif tidak tersedia atau telah berubah.']);
            }
            $at = PlacementRules::instant($semester, $effectiveAt);
            $classroom = PlacementRules::classroom($classroomId, $semester);
            $enrollments = $student->enrollments()->where('semester_id', $semester->id)->orderBy('id')->lockForUpdate()->get();
            $old = $enrollments->first(fn ($row) => $row->valid_from->lessThanOrEqualTo($at) && (! $row->valid_until || $row->valid_until->greaterThan($at)));
            if (! $old || $old->valid_from->greaterThanOrEqualTo($at)) {
                throw ValidationException::withMessages(['enrollment' => 'Penempatan lama yang dapat diakhiri tidak tersedia.']);
            }
            if ($old->classroom_id === $classroom->id) {
                throw ValidationException::withMessages(['classroom' => 'Kelas tujuan harus berbeda.']);
            }
            $end = $old->valid_until ?? (new SchoolCalendar)->end($semester);
            if ($end->greaterThan((new SchoolCalendar)->end($semester))) {
                throw ValidationException::withMessages(['enrollment' => 'Rentang penempatan lama berada di luar semester.']);
            }
            if ($old->pointRecords()->where('occurred_at', '>=', $at)->exists()) {
                throw ValidationException::withMessages(['effective_at' => 'Perpindahan tidak boleh mendahului catatan pada penempatan lama.']);
            }
            $old->update(['valid_until' => $at]);
            $new = Enrollment::create(['student_id' => $student->id, 'semester_id' => $semester->id, 'classroom_id' => $classroom->id, 'valid_from' => $at, 'valid_until' => $end]);
            PlacementRules::audit($admin, 'student', $student->id, 'student_moved', ['enrollment_id' => $old->id, 'classroom_id' => $old->classroom_id], ['enrollment_id' => $new->id, 'classroom_id' => $classroom->id, 'semester_id' => $semester->id]);

            return $new;
        }, 3);
    }
}

<?php

namespace App\Actions\Points;

use App\Models\Student;
use App\Models\StudentSelection;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SelectStudentManually
{
    public function execute(User $teacherUser, Student $student): StudentSelection
    {
        return DB::transaction(function () use ($teacherUser, $student): StudentSelection {
            $semester = StudentSelectionRules::activeSemester();
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $teacherUser = StudentSelectionRules::teacher($teacherUser, true);
            $student = StudentSelectionRules::student($student, true, true);
            $enrollment = StudentSelectionRules::enrollment($student, $semester);
            $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();

            return StudentSelection::create([
                'teacher_id' => $teacher->id,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'semester_id' => $semester->id,
                'source' => 'manual',
                'expires_at' => CarbonImmutable::now()->addMinutes(5),
            ]);
        }, 3);
    }
}

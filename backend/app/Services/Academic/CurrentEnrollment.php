<?php

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\Semester;
use App\Models\Student;

class CurrentEnrollment
{
    public function forStudent(Student $student, Semester $semester, string $at): ?Enrollment
    {
        $calendar = new SchoolCalendar;
        $instant = $calendar->instant($at);
        if (! $calendar->contains($semester, $instant)) {
            return null;
        }

        return Enrollment::where('student_id', $student->id)->where('semester_id', $semester->id)
            ->where('valid_from', '<=', $instant)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $instant))->first();
    }
}

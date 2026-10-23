<?php

namespace App\Services\Points;

use App\Models\Semester;
use App\Models\Student;
use App\Models\ThresholdAlert;
use App\Models\ThresholdLevel;

final class ThresholdEvaluator
{
    public function __construct(private readonly PointTotals $totals) {}

    /** Call inside the record/revision transaction after the semester and student locks are held. */
    public function evaluate(Student $student, Semester $semester): void
    {
        $policy = $semester->thresholdPolicies()->orderByDesc('version')->first();
        $now = now();

        if (! $policy) {
            ThresholdAlert::where('student_id', $student->id)
                ->where('semester_id', $semester->id)
                ->where('currently_reached', true)
                ->update(['currently_reached' => false, 'last_evaluated_at' => $now]);

            return;
        }

        ThresholdAlert::where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->whereHas('thresholdLevel', fn ($query) => $query->where('policy_id', '!=', $policy->id))
            ->where('currently_reached', true)
            ->update(['currently_reached' => false, 'last_evaluated_at' => $now]);

        $violationTotal = $this->totals->forStudent($student, $semester)['violation'];
        $levels = ThresholdLevel::where('policy_id', $policy->id)->orderBy('boundary_points')->get();
        foreach ($levels as $level) {
            $alert = ThresholdAlert::where('student_id', $student->id)
                ->where('semester_id', $semester->id)
                ->where('threshold_level_id', $level->id)
                ->lockForUpdate()
                ->first();
            $reached = $violationTotal >= $level->boundary_points;

            if ($alert) {
                $alert->update(['currently_reached' => $reached, 'last_evaluated_at' => $now]);
            } elseif ($reached) {
                ThresholdAlert::create([
                    'student_id' => $student->id,
                    'semester_id' => $semester->id,
                    'threshold_level_id' => $level->id,
                    'reached_at' => $now,
                    'currently_reached' => true,
                    'last_evaluated_at' => $now,
                ]);
            }
        }
    }
}

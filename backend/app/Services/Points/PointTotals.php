<?php

namespace App\Services\Points;

use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class PointTotals
{
    /**
     * @return array{achievement:int,violation:int}
     */
    public function forStudent(Student $student, Semester $semester): array
    {
        $totals = DB::table('point_records as records')
            ->join('record_revisions as revisions', function ($join): void {
                $join->on('revisions.point_record_id', '=', 'records.id')
                    ->whereRaw('revisions.revision_number = (select max(latest.revision_number) from record_revisions as latest where latest.point_record_id = records.id)');
            })
            ->where('records.student_id', $student->id)
            ->where('records.semester_id', $semester->id)
            ->where('revisions.status', 'active')
            ->selectRaw("COALESCE(SUM(CASE WHEN revisions.category_snapshot = 'achievement' THEN revisions.points_snapshot ELSE 0 END), 0) AS achievement")
            ->selectRaw("COALESCE(SUM(CASE WHEN revisions.category_snapshot = 'violation' THEN revisions.points_snapshot ELSE 0 END), 0) AS violation")
            ->first();

        return ['achievement' => (int) $totals->achievement, 'violation' => (int) $totals->violation];
    }
}

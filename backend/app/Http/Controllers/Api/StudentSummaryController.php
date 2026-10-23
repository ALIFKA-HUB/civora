<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Academic\SchoolCalendar;
use App\Services\Points\PointTotals;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentSummaryController extends Controller
{
    public function show(Request $request, PointTotals $totals): JsonResponse
    {
        abort_unless($request->user()->role === 'student', 403, 'Akses ditolak.');
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $semester = Semester::where('status', 'active')->first();
        if (! $semester || ! (new SchoolCalendar)->contains($semester, CarbonImmutable::now())) {
            return response()->json(['data' => ['semester' => null, 'totals' => null, 'latest_threshold_status' => null]]);
        }

        $policy = $semester->thresholdPolicies()->orderByDesc('version')->first();
        $status = null;
        if ($policy) {
            $status = $student->thresholdAlerts()
                ->where('semester_id', $semester->id)
                ->where('currently_reached', true)
                ->whereHas('thresholdLevel', fn ($query) => $query->where('policy_id', $policy->id))
                ->with('thresholdLevel')
                ->get()
                ->sortByDesc(fn ($alert) => $alert->thresholdLevel->boundary_points)
                ->first();
            $status = $status ? [
                'level_id' => $status->thresholdLevel->id,
                'label' => $status->thresholdLevel->label,
                'boundary_points' => $status->thresholdLevel->boundary_points,
                'recommended_action' => $status->thresholdLevel->recommended_action,
                'reached_at' => $status->reached_at?->toISOString(),
            ] : null;
        }

        return response()->json(['data' => [
            'semester' => ['id' => $semester->id, 'name' => $semester->name],
            'totals' => $totals->forStudent($student, $semester),
            'latest_threshold_status' => $status,
        ]]);
    }
}

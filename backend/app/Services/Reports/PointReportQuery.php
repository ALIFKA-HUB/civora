<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PointReportQuery
{
    private const FILTERS = ['semester_id', 'major_id', 'classroom_id', 'student_id', 'category', 'from_date', 'to_date', 'status'];

    public function validateFilters(array $filters): array
    {
        $unknown = array_diff(array_keys($filters), self::FILTERS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => 'Filter laporan tidak dikenal: '.implode(', ', $unknown).'.']);
        }

        return Validator::make($filters, [
            'semester_id' => ['required', 'uuid', 'exists:semesters,id'],
            'major_id' => ['sometimes', 'nullable', 'uuid', 'exists:majors,id'],
            'classroom_id' => ['sometimes', 'nullable', 'uuid', 'exists:classrooms,id'],
            'student_id' => ['sometimes', 'nullable', 'uuid', 'exists:students,id'],
            'category' => ['sometimes', 'nullable', 'in:achievement,violation'],
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'status' => ['sometimes', 'nullable', 'in:active,cancelled'],
        ])->validate();
    }

    /** @return array{summary: array{achievement: int, violation: int}, rows: array, generated_at: string} */
    public function build(array $filters): array
    {
        $filters = $this->validateFilters($filters);
        $setting = DB::table('school_settings')->first();
        $timezone = $setting?->timezone ?: config('app.school_timezone', 'Asia/Jakarta');
        $latestRevision = DB::table('record_revisions')
            ->selectRaw('DISTINCT ON (point_record_id) *')
            ->orderBy('point_record_id')
            ->orderByDesc('revision_number');

        $query = DB::table('point_records as record')
            ->joinSub($latestRevision, 'revision', fn ($join) => $join->on('revision.point_record_id', '=', 'record.id'))
            ->join('students as student', 'student.id', '=', 'record.student_id')
            ->join('users as student_user', 'student_user.id', '=', 'student.user_id')
            ->join('semesters as semester', 'semester.id', '=', 'record.semester_id')
            ->join('enrollments as enrollment', 'enrollment.id', '=', 'record.enrollment_id')
            ->join('classrooms as classroom', 'classroom.id', '=', 'enrollment.classroom_id')
            ->select([
                'record.id', 'student.nisn', 'student_user.name as student_name', 'semester.name as semester_name',
                'semester.start_date as semester_start_date', 'semester.end_date as semester_end_date',
                'record.occurred_at', 'record.class_snapshot as class', 'record.major_snapshot as major',
                'revision.category_snapshot as category', 'revision.name_snapshot as point_name',
                'revision.points_snapshot as points', 'revision.status', 'revision.description',
            ])
            ->where('record.semester_id', $filters['semester_id']);

        if (! empty($filters['student_id'])) {
            $query->where('record.student_id', $filters['student_id']);
        }
        if (! empty($filters['classroom_id'])) {
            $query->where('enrollment.classroom_id', $filters['classroom_id']);
        }
        if (! empty($filters['major_id'])) {
            $query->where('classroom.major_id', $filters['major_id']);
        }
        if (! empty($filters['category'])) {
            $query->where('revision.category_snapshot', $filters['category']);
        }
        if (! empty($filters['status'])) {
            $query->where('revision.status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->where('record.occurred_at', '>=', CarbonImmutable::parse($filters['from_date'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['to_date'])) {
            $query->where('record.occurred_at', '<', CarbonImmutable::parse($filters['to_date'], $timezone)->addDay()->startOfDay()->utc());
        }

        // One query supplies both detail rows and totals, so concurrent revisions cannot split the snapshot.
        $rows = $query->orderByDesc('record.occurred_at')->orderBy('record.id')->get()->map(function (object $row) use ($timezone): array {
            return [
                'id' => (string) $row->id,
                'nisn' => (string) $row->nisn,
                'student' => (string) $row->student_name,
                'semester' => (string) $row->semester_name,
                'semester_start_date' => (string) $row->semester_start_date,
                'semester_end_date' => (string) $row->semester_end_date,
                'occurred_at' => CarbonImmutable::parse($row->occurred_at)->setTimezone($timezone)->format('Y-m-d H:i:s'),
                'class' => (string) $row->class,
                'major' => (string) $row->major,
                'category' => (string) $row->category,
                'point_name' => (string) $row->point_name,
                'points' => (int) $row->points,
                'status' => (string) $row->status,
                'description' => (string) $row->description,
            ];
        })->all();

        $summary = ['achievement' => 0, 'violation' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'active') {
                $summary[$row['category']] += $row['points'];
            }
        }

        return ['summary' => $summary, 'rows' => $rows, 'generated_at' => CarbonImmutable::now('UTC')->toIso8601String()];
    }
}

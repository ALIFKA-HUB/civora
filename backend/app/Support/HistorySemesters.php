<?php

namespace App\Support;

use App\Models\Semester;
use App\Services\Academic\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class HistorySemesters
{
    public static function forRecords(Builder $records): array
    {
        $ids = (clone $records)->reorder()->distinct()->pluck('semester_id')->all();
        $current = Semester::where('status', 'active')->first();
        if ($current && (new SchoolCalendar)->contains($current, CarbonImmutable::now())) {
            $ids[] = $current->id;
        }

        return Semester::whereIn('id', array_unique($ids))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get(['id', 'name', 'start_date', 'end_date'])
            ->map(fn (Semester $semester) => [
                'id' => $semester->id,
                'name' => $semester->name,
                'start_date' => $semester->start_date?->format('Y-m-d'),
                'end_date' => $semester->end_date?->format('Y-m-d'),
                'is_current' => $semester->id === $current?->id,
            ])->all();
    }
}

<?php

namespace App\Services\Academic;

use App\Models\SchoolSetting;
use App\Models\Semester;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class SchoolCalendar
{
    public function timezone(): string
    {
        return SchoolSetting::value('timezone') ?? config('app.school_timezone');
    }

    public function instant(string $at): CarbonImmutable
    {
        Validator::make(['effective_at' => $at], ['effective_at' => ['required', 'date']], ['date' => 'Waktu penempatan tidak valid.'])->validate();

        return CarbonImmutable::parse($at, $this->timezone())->utc();
    }

    public function start(Semester $semester): CarbonImmutable
    {
        return CarbonImmutable::parse($semester->start_date->format('Y-m-d'), $this->timezone())->utc();
    }

    public function end(Semester $semester): CarbonImmutable
    {
        return CarbonImmutable::parse($semester->end_date->format('Y-m-d'), $this->timezone())->addDay()->utc();
    }

    public function contains(Semester $semester, CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->start($semester)) && $at->lessThan($this->end($semester));
    }
}

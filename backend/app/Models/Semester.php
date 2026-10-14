<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    use HasUuids;

    protected $table = 'semesters';

    protected $fillable = ['academic_year_id', 'name', 'start_date', 'end_date', 'status'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'semester_id');
    }

    public function studentSelections(): HasMany
    {
        return $this->hasMany(StudentSelection::class, 'semester_id');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'semester_id');
    }

    public function thresholdPolicies(): HasMany
    {
        return $this->hasMany(ThresholdPolicy::class, 'semester_id');
    }

    public function thresholdAlerts(): HasMany
    {
        return $this->hasMany(ThresholdAlert::class, 'semester_id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'semester_id');
    }
}

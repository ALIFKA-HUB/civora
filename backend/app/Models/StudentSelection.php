<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentSelection extends Model
{
    use HasUuids;

    protected $table = 'student_selections';

    protected $fillable = ['teacher_id', 'student_id', 'enrollment_id', 'semester_id', 'source', 'expires_at', 'consumed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function qrRedemptions(): HasMany
    {
        return $this->hasMany(QrRedemption::class, 'selection_id');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'selection_id');
    }
}

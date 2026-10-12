<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use HasUuids;

    protected $table = 'enrollments';

    protected $fillable = ['student_id', 'semester_id', 'classroom_id', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    public function studentSelections(): HasMany
    {
        return $this->hasMany(StudentSelection::class, 'enrollment_id');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'enrollment_id');
    }
}

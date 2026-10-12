<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasUuids;

    protected $table = 'classrooms';

    protected $fillable = ['academic_year_id', 'major_id', 'code', 'name', 'grade', 'active'];

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class, 'major_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'classroom_id');
    }
}

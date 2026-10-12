<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use HasUuids;

    protected $table = 'academic_years';

    protected $fillable = ['name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class, 'academic_year_id');
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'academic_year_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasUuids;

    protected $table = 'students';

    protected $fillable = ['user_id', 'nisn', 'education_status'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function studentSelections(): HasMany
    {
        return $this->hasMany(StudentSelection::class, 'student_id');
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(QrToken::class, 'student_id');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'student_id');
    }

    public function thresholdAlerts(): HasMany
    {
        return $this->hasMany(ThresholdAlert::class, 'student_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThresholdAlert extends Model
{
    use HasUuids;

    protected $table = 'threshold_alerts';

    protected $fillable = ['student_id', 'semester_id', 'threshold_level_id', 'reached_at', 'currently_reached', 'last_evaluated_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'reached_at' => 'immutable_datetime',
            'currently_reached' => 'boolean',
            'last_evaluated_at' => 'immutable_datetime',
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

    public function thresholdLevel(): BelongsTo
    {
        return $this->belongsTo(ThresholdLevel::class, 'threshold_level_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'threshold_alert_id');
    }
}

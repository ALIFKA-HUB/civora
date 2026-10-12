<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThresholdLevel extends Model
{
    use HasUuids;

    protected $table = 'threshold_levels';

    protected $fillable = ['policy_id', 'boundary_points', 'label', 'recommended_action'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'boundary_points' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ThresholdPolicy::class, 'policy_id');
    }

    public function thresholdAlerts(): HasMany
    {
        return $this->hasMany(ThresholdAlert::class, 'threshold_level_id');
    }
}

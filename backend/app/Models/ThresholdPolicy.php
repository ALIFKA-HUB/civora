<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThresholdPolicy extends Model
{
    use HasUuids;

    protected $table = 'threshold_policies';

    protected $fillable = ['semester_id', 'version', 'created_by', 'reason'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function thresholdLevels(): HasMany
    {
        return $this->hasMany(ThresholdLevel::class, 'policy_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    use HasUuids;

    protected $table = 'follow_ups';

    protected $fillable = ['threshold_alert_id', 'performed_by', 'action', 'scheduled_or_performed_at', 'notes', 'status'];

    protected function casts(): array
    {
        return [
            'scheduled_or_performed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function thresholdAlert(): BelongsTo
    {
        return $this->belongsTo(ThresholdAlert::class, 'threshold_alert_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewRequest extends Model
{
    use HasUuids;

    protected $table = 'review_requests';

    protected $fillable = ['point_record_id', 'requester_user_id', 'kind', 'reason', 'proposed_change', 'status', 'reviewed_revision', 'decided_by', 'decision_reason', 'decided_at'];

    protected function casts(): array
    {
        return [
            'proposed_change' => 'array',
            'reviewed_revision' => 'integer',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function pointRecord(): BelongsTo
    {
        return $this->belongsTo(PointRecord::class, 'point_record_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

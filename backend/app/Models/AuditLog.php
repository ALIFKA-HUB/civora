<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    protected $fillable = ['actor_user_id', 'action', 'resource_type', 'resource_id', 'safe_before', 'safe_after', 'reason', 'request_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'safe_before' => 'array',
            'safe_after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

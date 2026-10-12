<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    protected $fillable = ['event_key', 'type', 'resource_id', 'payload', 'processed_at', 'attempts', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

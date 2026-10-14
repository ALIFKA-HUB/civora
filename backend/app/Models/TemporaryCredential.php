<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryCredential extends Model
{
    use HasUuids;

    protected $table = 'temporary_credentials';

    protected $fillable = ['user_id', 'encrypted_secret', 'expires_at', 'consumed_at', 'generated_by', 'reason'];

    protected $hidden = ['encrypted_secret'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'encrypted_secret' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

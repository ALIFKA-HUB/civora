<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceToken extends Model
{
    use HasUuids;

    protected $table = 'device_tokens';

    protected $fillable = ['user_id', 'app', 'token', 'platform', 'active', 'personal_access_token_id'];

    public const CREATED_AT = null;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}

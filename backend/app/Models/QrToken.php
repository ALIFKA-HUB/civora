<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

class QrToken extends Model
{
    use HasUuids;

    public function issuingToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'session_id');
    }

    protected $table = 'qr_tokens';

    protected $fillable = ['student_id', 'session_id', 'token_hash', 'issued_at', 'expires_at', 'consumed_at', 'revoked_at'];

    public $timestamps = false;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function qrRedemptions(): HasMany
    {
        return $this->hasMany(QrRedemption::class, 'qr_token_id');
    }
}

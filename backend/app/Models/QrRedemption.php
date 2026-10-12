<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrRedemption extends Model
{
    use HasUuids;

    protected $table = 'qr_redemptions';

    protected $fillable = ['qr_token_id', 'teacher_id', 'request_key', 'selection_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function qrToken(): BelongsTo
    {
        return $this->belongsTo(QrToken::class, 'qr_token_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(StudentSelection::class, 'selection_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasUuids;

    protected $table = 'teachers';

    protected $fillable = ['user_id', 'nip', 'teacher_code'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function studentSelections(): HasMany
    {
        return $this->hasMany(StudentSelection::class, 'teacher_id');
    }

    public function qrRedemptions(): HasMany
    {
        return $this->hasMany(QrRedemption::class, 'teacher_id');
    }

    public function pointRecords(): HasMany
    {
        return $this->hasMany(PointRecord::class, 'teacher_id');
    }
}

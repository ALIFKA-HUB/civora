<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointTypeVersion extends Model
{
    use HasUuids;

    protected $table = 'point_type_versions';

    protected $fillable = ['point_type_id', 'version', 'name', 'description', 'points', 'created_by'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'points' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function pointType(): BelongsTo
    {
        return $this->belongsTo(PointType::class, 'point_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recordRevisions(): HasMany
    {
        return $this->hasMany(RecordRevision::class, 'point_type_version_id');
    }
}

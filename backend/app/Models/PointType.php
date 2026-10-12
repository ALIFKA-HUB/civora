<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointType extends Model
{
    use HasUuids;

    protected $table = 'point_types';

    protected $fillable = ['code', 'category', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function pointTypeVersions(): HasMany
    {
        return $this->hasMany(PointTypeVersion::class, 'point_type_id');
    }
}

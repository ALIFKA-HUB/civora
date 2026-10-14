<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Major extends Model
{
    use HasUuids;

    protected $table = 'majors';

    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'major_id');
    }
}

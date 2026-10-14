<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SchoolSetting extends Model
{
    use HasUuids;

    protected $table = 'school_settings';

    protected $fillable = ['name', 'timezone', 'logo_path', 'settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

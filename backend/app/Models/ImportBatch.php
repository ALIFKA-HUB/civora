<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    use HasUuids;

    protected $table = 'import_batches';

    protected $fillable = ['admin_id', 'entity_type', 'file_path', 'semester_id', 'status', 'row_count', 'parsed_rows', 'error_summary', 'confirmed_at'];

    protected $hidden = ['file_path', 'parsed_rows'];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'error_summary' => 'array',
            'parsed_rows' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }
}

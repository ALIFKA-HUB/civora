<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasUuids;

    protected $table = 'report_exports';

    protected $fillable = ['admin_id', 'filters', 'status', 'file_path', 'generated_at', 'expires_at', 'generation_token', 'generation_lease_until'];

    protected $hidden = ['file_path', 'generation_token', 'generation_lease_until'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'generation_lease_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}

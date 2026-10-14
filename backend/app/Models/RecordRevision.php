<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordRevision extends Model
{
    use HasUuids;

    protected $table = 'record_revisions';

    protected $fillable = ['point_record_id', 'revision_number', 'point_type_version_id', 'points_snapshot', 'name_snapshot', 'category_snapshot', 'description', 'attachment_id', 'status', 'changed_by', 'reason'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'points_snapshot' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function pointRecord(): BelongsTo
    {
        return $this->belongsTo(PointRecord::class, 'point_record_id');
    }

    public function pointTypeVersion(): BelongsTo
    {
        return $this->belongsTo(PointTypeVersion::class, 'point_type_version_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

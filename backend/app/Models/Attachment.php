<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attachment extends Model
{
    use HasUuids;

    protected $table = 'attachments';

    protected $fillable = ['owner_user_id', 'private_path', 'mime_type', 'size', 'checksum'];

    public const UPDATED_AT = null;

    protected $hidden = ['private_path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function recordRevisions(): HasMany
    {
        return $this->hasMany(RecordRevision::class, 'attachment_id');
    }
}

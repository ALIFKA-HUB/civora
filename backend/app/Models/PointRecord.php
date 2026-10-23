<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PointRecord extends Model
{
    use HasUuids;

    protected $table = 'point_records';

    protected $fillable = ['student_id', 'teacher_id', 'enrollment_id', 'semester_id', 'selection_id', 'source', 'occurred_at', 'class_snapshot', 'major_snapshot', 'idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(StudentSelection::class, 'selection_id');
    }

    public function reviewRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'point_record_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RecordRevision::class, 'point_record_id');
    }

    public function latestRevision(): HasOne
    {
        // Laravel ofMany also aggregates UUID primary keys, which PostgreSQL cannot MAX.
        return $this->hasOne(RecordRevision::class, 'point_record_id')
            ->where('revision_number', fn ($query) => $query
                ->selectRaw('MAX(latest.revision_number)')
                ->from('record_revisions as latest')
                ->whereColumn('latest.point_record_id', 'record_revisions.point_record_id'));
    }
}

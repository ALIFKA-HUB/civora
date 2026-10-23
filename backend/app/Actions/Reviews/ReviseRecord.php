<?php

namespace App\Actions\Reviews;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\PointRecord;
use App\Models\RecordRevision;
use App\Models\ReviewRequest;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Points\ThresholdEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviseRecord
{
    public function execute(User $admin, PointRecord $record, string $reason, int $expectedRevision, array $changes): PointRecord
    {
        $identity = PointRecord::whereKey($record->id)->firstOrFail();
        $reason = $this->reason($reason);
        ReviewChanges::validate($changes);

        return DB::transaction(function () use ($admin, $identity, $reason, $expectedRevision, $changes): PointRecord {
            $semester = Semester::whereKey($identity->semester_id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($identity->student_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($actor);
            $record = PointRecord::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $current = $record->revisions()->orderByDesc('revision_number')->lockForUpdate()->firstOrFail();
            if ($current->revision_number !== $expectedRevision) {
                ReviewChanges::conflict('RECORD_REVISION_CONFLICT', 'Catatan berubah. Muat ulang sebelum mengoreksi.');
            }

            $before = $this->snapshot($current);
            $revision = ReviewChanges::append($actor, $record, $current, $reason, $changes);
            $pending = $record->reviewRequests()->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                if ($pending->reviewed_revision !== $expectedRevision) {
                    ReviewChanges::conflict('RECORD_REVISION_CONFLICT', 'Pemeriksaan telah memeriksa versi lama. Muat ulang antrean.');
                }
                $this->approvePending($pending, $actor, $reason, $expectedRevision);
            }
            $this->recordChange($actor, $record, $student, $semester, $before, $revision);

            return $record->load(['latestRevision', 'student.user', 'teacher.user', 'semester']);
        }, 3);
    }

    public function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Alasan harus berisi 5–1.000 karakter.']);
        }

        return $reason;
    }

    public function snapshot(RecordRevision $revision): array
    {
        return [
            'revision_number' => $revision->revision_number,
            'point_type_version_id' => $revision->point_type_version_id,
            'points' => $revision->points_snapshot,
            'name' => $revision->name_snapshot,
            'category' => $revision->category_snapshot,
            'description' => $revision->description,
            'attachment_id' => $revision->attachment_id,
            'status' => $revision->status,
        ];
    }

    public function recordChange(User $actor, PointRecord $record, Student $student, Semester $semester, array $before, RecordRevision $revision): void
    {
        app(ThresholdEvaluator::class)->evaluate($student, $semester);
        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => 'point_record_revised',
            'resource_type' => 'point_record',
            'resource_id' => $record->id,
            'safe_before' => $before,
            'safe_after' => $this->snapshot($revision),
            'reason' => $revision->reason,
            'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
        ]);
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'point-record-revised:'.$record->id.':'.$revision->revision_number,
            'type' => 'point_record_revised',
            'resource_id' => $record->id,
            'payload' => json_encode(['student_id' => $student->id, 'teacher_id' => $record->teacher_id, 'revision_number' => $revision->revision_number], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    public function approvePending(ReviewRequest $review, User $actor, string $reason, int $revision): void
    {
        $review->update(['status' => 'approved', 'reviewed_revision' => $revision, 'decided_by' => $actor->id, 'decision_reason' => $reason, 'decided_at' => CarbonImmutable::now()]);
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'review-decision:'.$review->id,
            'type' => 'review_decided',
            'resource_id' => $review->point_record_id,
            'payload' => json_encode(['requester_user_id' => $review->requester_user_id, 'teacher_id' => $review->pointRecord->teacher_id], JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}

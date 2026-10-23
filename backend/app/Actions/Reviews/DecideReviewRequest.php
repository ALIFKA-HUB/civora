<?php

namespace App\Actions\Reviews;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\ReviewRequest;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DecideReviewRequest
{
    public function __construct(private readonly ReviseRecord $reviseRecord) {}

    public function execute(User $admin, ReviewRequest $request, string $decision, string $reason, int $expectedRevision, array $changes): ReviewRequest
    {
        $identity = ReviewRequest::with('pointRecord')->whereKey($request->id)->firstOrFail();
        $recordIdentity = $identity->pointRecord;
        $reason = $this->reviseRecord->reason($reason);
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan harus approve atau reject.']);
        }
        if ($decision === 'reject' && $changes !== []) {
            throw ValidationException::withMessages(['changes' => 'Penolakan tidak menerima perubahan catatan.']);
        }
        if ($decision === 'approve') {
            ReviewChanges::validate($changes);
        }

        return DB::transaction(function () use ($admin, $identity, $recordIdentity, $decision, $reason, $expectedRevision, $changes): ReviewRequest {
            $semester = Semester::whereKey($recordIdentity->semester_id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($recordIdentity->student_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            AccountSecurity::admin($actor);
            $record = $recordIdentity->newQuery()->whereKey($recordIdentity->id)->lockForUpdate()->firstOrFail();
            $review = ReviewRequest::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $current = $record->revisions()->orderByDesc('revision_number')->lockForUpdate()->firstOrFail();
            if ($review->status !== 'pending' || $current->revision_number !== $expectedRevision || $review->reviewed_revision !== $expectedRevision) {
                ReviewChanges::conflict('RECORD_REVISION_CONFLICT', 'Pemeriksaan atau catatan telah berubah. Muat ulang antrean.');
            }

            $before = $this->reviseRecord->snapshot($current);
            if ($decision === 'approve') {
                $revision = ReviewChanges::append($actor, $record, $current, $reason, $changes);
                $review->update(['status' => 'approved', 'decided_by' => $actor->id, 'decision_reason' => $reason, 'decided_at' => CarbonImmutable::now()]);
                $this->reviseRecord->recordChange($actor, $record, $student, $semester, $before, $revision);
            } else {
                $review->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decision_reason' => $reason, 'decided_at' => CarbonImmutable::now()]);
            }

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'action' => 'review_'.$decision,
                'resource_type' => 'review_request',
                'resource_id' => $review->id,
                'safe_before' => ['status' => 'pending', 'reviewed_revision' => $expectedRevision],
                'safe_after' => ['status' => $decision === 'approve' ? 'approved' : 'rejected'],
                'reason' => $reason,
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);
            DB::table('outbox_events')->insert([
                'id' => (string) Str::uuid(),
                'event_key' => 'review-decision:'.$review->id,
                'type' => 'review_decided',
                'resource_id' => $record->id,
                'payload' => json_encode(['requester_user_id' => $review->requester_user_id, 'teacher_id' => $record->teacher_id], JSON_THROW_ON_ERROR),
                'created_at' => CarbonImmutable::now(),
            ]);

            return $review->fresh();
        }, 3);
    }
}

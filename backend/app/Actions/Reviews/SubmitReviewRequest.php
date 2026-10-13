<?php

namespace App\Actions\Reviews;

use App\Actions\Accounts\AccountSecurity;
use App\Models\AuditLog;
use App\Models\PointRecord;
use App\Models\ReviewRequest;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitReviewRequest
{
    public function execute(User $requester, PointRecord $record, string $kind, string $reason, array $proposedChange): ReviewRequest
    {
        $identity = PointRecord::whereKey($record->id)->firstOrFail();

        return DB::transaction(function () use ($requester, $identity, $kind, $reason, $proposedChange): ReviewRequest {
            $semester = Semester::whereKey($identity->semester_id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($identity->student_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($requester->id)->lockForUpdate()->firstOrFail();
            $record = PointRecord::whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $revision = $record->revisions()->orderByDesc('revision_number')->lockForUpdate()->first();

            if (! AccountSecurity::active($actor) || ! $revision || $revision->status !== 'active') {
                throw new AuthorizationException('Akses ditolak.');
            }

            if ($actor->role === 'student' && $student->user_id === $actor->id) {
                if ($kind !== 'student_objection' || $proposedChange !== []) {
                    throw ValidationException::withMessages(['proposed_change' => 'Keberatan siswa hanya menerima alasan.']);
                }
            } elseif ($actor->role === 'teacher' && Teacher::where('user_id', $actor->id)->value('id') === $record->teacher_id) {
                if ($kind !== 'teacher_correction') {
                    throw ValidationException::withMessages(['kind' => 'Jenis permintaan tidak sesuai dengan peran.']);
                }
                $proposedChange = ReviewChanges::validate($proposedChange);
            } else {
                throw new AuthorizationException('Akses ditolak.');
            }

            if (! in_array($kind, ['student_objection', 'teacher_correction'], true)) {
                throw ValidationException::withMessages(['kind' => 'Jenis pemeriksaan tidak valid.']);
            }
            if (array_key_exists('attachment_id', $proposedChange)) {
                ReviewChanges::validateAttachment($record, $proposedChange['attachment_id']);
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['reason' => 'Alasan harus berisi 5–1.000 karakter.']);
            }
            if ($record->reviewRequests()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['record' => 'Sudah ada pemeriksaan yang menunggu untuk catatan ini.']);
            }

            $review = $record->reviewRequests()->create([
                'requester_user_id' => $actor->id,
                'kind' => $kind,
                'reason' => $reason,
                'proposed_change' => $kind === 'teacher_correction' ? $proposedChange : [],
                'status' => 'pending',
                'reviewed_revision' => $revision->revision_number,
            ]);
            AuditLog::create([
                'actor_user_id' => $actor->id,
                'action' => 'review_submitted',
                'resource_type' => 'review_request',
                'resource_id' => $review->id,
                'safe_after' => ['record_id' => $record->id, 'kind' => $kind, 'status' => 'pending', 'reviewed_revision' => $revision->revision_number],
                'reason' => $reason,
                'request_id' => request()->attributes->get('request_id') ?? (string) Str::uuid(),
            ]);
            DB::table('outbox_events')->insert([
                'id' => (string) Str::uuid(),
                'event_key' => 'review-submitted:'.$review->id,
                'type' => 'review_submitted',
                'resource_id' => $record->id,
                'payload' => json_encode(['requester_user_id' => $actor->id, 'teacher_id' => $record->teacher_id], JSON_THROW_ON_ERROR),
                'created_at' => CarbonImmutable::now(),
            ]);

            return $review;
        }, 3);
    }
}

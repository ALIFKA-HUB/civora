<?php

namespace Tests\Feature;

use App\Actions\Catalog\RevisePointType;
use App\Actions\Reviews\DecideReviewRequest;
use App\Actions\Reviews\ReviseRecord;
use App\Actions\Reviews\SubmitReviewRequest;
use App\Models\Attachment;
use App\Models\PointRecord;
use App\Models\PointType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Points\PointTotals;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class ReviewDecisionTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private User $studentUser;

    private User $teacherUser;

    private PointRecord $record;

    private Semester $semester;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        DB::table('review_requests')->delete();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->studentUser = User::findOrFail(DB::table('students')->value('user_id'));
        $this->studentUser->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacherUser = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacherUser->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        $this->student = Student::findOrFail($this->rows['students']['id']);
    }

    public function test_student_can_submit_only_reason_for_own_active_record(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->studentUser, $this->record, 'student_objection', 'Saya ingin catatan ini diperiksa.', []);

        self::assertSame('pending', $review->status);
        self::assertSame([], $review->proposed_change);
        self::assertSame($this->student->id, $this->record->fresh()->student_id);
        self::assertSame(['achievement' => 10, 'violation' => 0], app(PointTotals::class)->forStudent($this->student, $this->semester));
    }

    public function test_teacher_can_request_correction_only_for_their_own_record(): void
    {
        $other = User::create(['role' => 'teacher', 'name' => 'Other teacher', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $other->id, 'teacher_code' => 'G002']);

        try {
            app(SubmitReviewRequest::class)->execute($other, $this->record, 'teacher_correction', 'Mohon tinjau catatan ini.', ['operation' => 'cancel']);
            self::fail('A teacher must not request correction for another teacher record.');
        } catch (AuthorizationException) {
            self::assertDatabaseCount('review_requests', 0);
        }

        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Mohon koreksi keterangan ini.', ['operation' => 'revise', 'description' => 'Corrected description']);
        self::assertSame('teacher_correction', $review->kind);
    }

    public function test_student_and_teacher_share_one_pending_slot_and_invalid_proposals_are_rejected(): void
    {
        app(SubmitReviewRequest::class)->execute($this->studentUser, $this->record, 'student_objection', 'Saya ingin catatan ini diperiksa.', []);

        $this->expectException(ValidationException::class);
        app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Mohon koreksi catatan ini.', ['operation' => 'cancel']);
    }

    public function test_admin_approval_appends_revision_and_uses_current_catalog_snapshot(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Mohon koreksi catatan ini.', ['operation' => 'revise', 'description' => 'Corrected description']);
        $before = app(PointTotals::class)->forStudent($this->student, $this->semester);

        $updated = app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Koreksi diverifikasi oleh sekolah.', 1, $review->proposed_change);

        self::assertSame('approved', $updated->status);
        self::assertSame($this->student->id, $this->record->fresh()->student_id);
        self::assertSame(2, $this->record->revisions()->count());
        self::assertSame('Corrected description', $this->record->fresh()->latestRevision->description);
        self::assertSame($before, app(PointTotals::class)->forStudent($this->student, $this->semester));
        $this->assertDatabaseHas('audit_logs', ['action' => 'point_record_revised', 'resource_id' => $this->record->id]);
    }

    public function test_reject_keeps_total_and_requires_reason_and_empty_changes(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->studentUser, $this->record, 'student_objection', 'Saya ingin catatan ini diperiksa.', []);
        $before = app(PointTotals::class)->forStudent($this->student, $this->semester);
        $rejected = app(DecideReviewRequest::class)->execute($this->admin, $review, 'reject', 'Bukti mendukung catatan awal.', 1, []);

        self::assertSame('rejected', $rejected->status);
        self::assertSame($before, app(PointTotals::class)->forStudent($this->student, $this->semester));
        self::assertSame(1, $this->record->revisions()->count());

        $this->expectException(ValidationException::class);
        app(DecideReviewRequest::class)->execute($this->admin, $rejected, 'reject', 'bad', 1, []);
    }

    public function test_second_decision_with_same_expected_revision_conflicts_without_another_revision(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->studentUser, $this->record, 'student_objection', 'Saya ingin catatan ini diperiksa.', []);
        app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Catatan dibatalkan setelah pemeriksaan.', 1, ['operation' => 'cancel']);

        try {
            app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Keputusan kedua.', 1, ['operation' => 'cancel']);
            self::fail('A second decision against revision 1 must conflict.');
        } catch (HttpResponseException $exception) {
            self::assertSame(409, $exception->getResponse()->getStatusCode());
        }

        self::assertSame(2, $this->record->revisions()->count());
    }

    public function test_direct_admin_revision_resolves_pending_review_and_cancellation_excludes_total(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->studentUser, $this->record, 'student_objection', 'Saya ingin catatan ini diperiksa.', []);
        $revised = app(ReviseRecord::class)->execute($this->admin, $this->record, 'Dibatalkan setelah verifikasi.', 1, ['operation' => 'cancel']);

        self::assertSame(2, $revised->latestRevision->revision_number);
        self::assertSame('cancelled', $revised->latestRevision->status);
        self::assertSame('approved', $review->fresh()->status);
        self::assertSame(['achievement' => 0, 'violation' => 0], app(PointTotals::class)->forStudent($this->student, $this->semester));
        self::assertDatabaseHas('outbox_events', ['event_key' => 'point-record-revised:'.$this->record->id.':2']);
    }

    public function test_revision_is_allowed_for_closed_semester_without_reopening_it(): void
    {
        $this->semester->update(['status' => 'closed']);
        $revised = app(ReviseRecord::class)->execute($this->admin, $this->record, 'Keterangan arsip diperbaiki.', 1, ['operation' => 'revise', 'description' => 'Historical corrected description']);

        self::assertSame('closed', $this->semester->fresh()->status);
        self::assertSame('Historical corrected description', $revised->latestRevision->description);
    }

    public function test_teacher_cannot_submit_an_attachment_owned_by_another_teacher(): void
    {
        $otherTeacher = User::create(['role' => 'teacher', 'name' => 'Evidence owner', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $otherTeacher->id, 'teacher_code' => 'G002']);
        $attachment = $this->attachment($otherTeacher->id);

        try {
            app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Lampiran koreksi.', ['operation' => 'revise', 'attachment_id' => $attachment->id]);
            self::fail('A teacher cannot propose another teacher’s private attachment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('attachment_id', $exception->errors());
        }

        self::assertDatabaseCount('review_requests', 0);
        self::assertSame($this->rows['students']['id'], $this->record->fresh()->student_id);
    }

    public function test_teacher_cannot_submit_evidence_already_used_by_another_record(): void
    {
        $attachment = $this->attachment($this->teacherUser->id);
        $this->otherRecordUsing($attachment->id);

        try {
            app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Lampiran telah dipakai.', ['operation' => 'revise', 'attachment_id' => $attachment->id]);
            self::fail('Evidence referenced by another record cannot be reused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('attachment_id', $exception->errors());
        }

        self::assertDatabaseCount('review_requests', 0);
    }

    public function test_teacher_can_propose_own_staged_evidence_and_reuse_evidence_from_same_record(): void
    {
        $staged = $this->attachment($this->teacherUser->id);
        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Lampiran baru milik pencatat.', ['operation' => 'revise', 'attachment_id' => $staged->id]);
        app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Bukti baru diperiksa.', 1, $review->proposed_change);
        self::assertSame($staged->id, $this->record->fresh()->latestRevision->attachment_id);

        $sameRecordEvidence = $this->rows['attachments']['id'];
        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Bukti catatan dipertahankan.', ['operation' => 'revise', 'attachment_id' => $sameRecordEvidence]);
        app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Bukti catatan tetap berlaku.', 2, $review->proposed_change);

        self::assertSame($sameRecordEvidence, $this->record->fresh()->latestRevision->attachment_id);
        self::assertSame(3, $this->record->revisions()->count());
    }

    public function test_admin_rechecks_attachment_eligibility_when_approving_a_stale_proposal(): void
    {
        $attachment = $this->attachment($this->teacherUser->id);
        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Lampiran masih tersedia.', ['operation' => 'revise', 'attachment_id' => $attachment->id]);
        $otherRecord = $this->otherRecordUsing($attachment->id, 'cancelled');
        $before = app(PointTotals::class)->forStudent($this->student, $this->semester);

        try {
            app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Lampiran diperiksa.', 1, $review->proposed_change);
            self::fail('Approval must recheck attachment use under its row lock.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('attachment_id', $exception->errors());
        }

        self::assertSame('pending', $review->fresh()->status);
        self::assertSame(1, $this->record->revisions()->count());
        self::assertSame($before, app(PointTotals::class)->forStudent($this->student, $this->semester));
        self::assertDatabaseHas('record_revisions', ['point_record_id' => $otherRecord, 'attachment_id' => $attachment->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'review_approve', 'resource_id' => $review->id]);
        $this->assertDatabaseMissing('outbox_events', ['event_key' => 'review-decision:'.$review->id]);
    }

    public function test_direct_admin_revision_rejects_foreign_and_cross_record_evidence(): void
    {
        $otherTeacher = User::create(['role' => 'teacher', 'name' => 'Another evidence owner', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $otherTeacher->id, 'teacher_code' => 'G003']);
        $foreign = $this->attachment($otherTeacher->id);
        $used = $this->attachment($this->teacherUser->id);
        $this->otherRecordUsing($used->id);

        foreach ([$foreign->id, $used->id] as $attachmentId) {
            try {
                app(ReviseRecord::class)->execute($this->admin, $this->record, 'Lampiran pengganti ditolak.', 1, ['operation' => 'revise', 'attachment_id' => $attachmentId]);
                self::fail('Direct admin revision must enforce the same evidence ownership rule.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('attachment_id', $exception->errors());
            }
        }

        self::assertSame(1, $this->record->revisions()->count());
        self::assertSame($this->rows['students']['id'], $this->record->fresh()->student_id);
    }

    public function test_approval_rechecks_catalog_latest_version_after_locking_mutable_type(): void
    {
        $review = app(SubmitReviewRequest::class)->execute($this->teacherUser, $this->record, 'teacher_correction', 'Gunakan versi katalog ini.', [
            'operation' => 'revise',
            'point_type_version_id' => $this->rows['point_type_versions']['id'],
        ]);
        app(RevisePointType::class)->execute($this->admin, PointType::findOrFail($this->rows['point_types']['id']), [
            'name' => 'Updated synthetic achievement',
            'points' => 20,
            'description' => 'Latest catalog version for stale proposal verification',
        ]);

        try {
            app(DecideReviewRequest::class)->execute($this->admin, $review, 'approve', 'Versi lama ditolak.', 1, $review->proposed_change);
            self::fail('A proposal with a stale point-type version must conflict.');
        } catch (HttpResponseException $exception) {
            self::assertSame(409, $exception->getResponse()->getStatusCode());
        }

        self::assertSame(1, $this->record->revisions()->count());
        self::assertSame('pending', $review->fresh()->status);
    }

    private function attachment(string $ownerId): Attachment
    {
        return Attachment::create([
            'owner_user_id' => $ownerId,
            'private_path' => 'evidence/'.Str::uuid().'.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'checksum' => str_repeat('a', 64),
        ]);
    }

    private function otherRecordUsing(string $attachmentId, string $status = 'active'): string
    {
        $selectionId = (string) Str::uuid();
        DB::table('student_selections')->insert([
            'id' => $selectionId,
            'teacher_id' => DB::table('teachers')->value('id'),
            'student_id' => $this->student->id,
            'enrollment_id' => $this->rows['enrollments']['id'],
            'semester_id' => $this->semester->id,
            'source' => 'manual',
            'expires_at' => now()->addMinutes(5),
        ]);
        $recordId = (string) Str::uuid();
        DB::table('point_records')->insert([
            'id' => $recordId,
            'student_id' => $this->student->id,
            'teacher_id' => DB::table('teachers')->value('id'),
            'enrollment_id' => $this->rows['enrollments']['id'],
            'semester_id' => $this->semester->id,
            'selection_id' => $selectionId,
            'source' => 'manual',
            'occurred_at' => now(),
            'class_snapshot' => 'Other Class',
            'major_snapshot' => 'Other Major',
            'idempotency_key' => 'attachment-claim-'.$recordId,
            'request_hash' => str_repeat('b', 64),
        ]);
        DB::table('record_revisions')->insert([
            'id' => (string) Str::uuid(),
            'point_record_id' => $recordId,
            'revision_number' => 1,
            'point_type_version_id' => $this->rows['point_type_versions']['id'],
            'points_snapshot' => 10,
            'name_snapshot' => 'Other evidence record',
            'category_snapshot' => 'achievement',
            'description' => 'Other record claims evidence',
            'attachment_id' => $attachmentId,
            'status' => $status,
            'changed_by' => $this->admin->id,
            'reason' => 'Initial other record',
            'created_at' => now(),
        ]);

        return $recordId;
    }
}

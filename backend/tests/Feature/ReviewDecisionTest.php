<?php

namespace Tests\Feature;

use App\Actions\Reviews\DecideReviewRequest;
use App\Actions\Reviews\ReviseRecord;
use App\Actions\Reviews\SubmitReviewRequest;
use App\Models\PointRecord;
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
}

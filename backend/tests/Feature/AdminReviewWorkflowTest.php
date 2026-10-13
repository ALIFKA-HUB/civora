<?php

namespace Tests\Feature;

use App\Actions\Catalog\RevisePointType;
use App\Actions\Reviews\DecideReviewRequest;
use App\Actions\Reviews\SubmitReviewRequest;
use App\Livewire\Admin\PointRecords;
use App\Livewire\Admin\ReviewDetail;
use App\Livewire\Admin\ReviewQueue;
use App\Models\Attachment;
use App\Models\PointRecord;
use App\Models\PointType;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class AdminReviewWorkflowTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private User $student;

    private User $teacher;

    private PointRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        DB::table('review_requests')->delete();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->student = User::findOrFail(DB::table('students')->value('user_id'));
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        foreach ([$this->admin, $this->student, $this->teacher] as $user) {
            $user->update(['account_status' => 'active', 'must_change_password' => false]);
        }
        $this->record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $this->actingAs($this->admin, 'web')->withSession(['admin_password_confirmed_at' => now()->timestamp]);
        Storage::fake('local');
    }

    public function test_queue_shows_pending_status_and_correct_student_or_teacher_requester_identity(): void
    {
        $studentReview = $this->review($this->student, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);
        Livewire::test(ReviewQueue::class)
            ->assertSee('Menunggu pemeriksaan')
            ->assertSee('Synthetic Student')
            ->assertSee(route('admin.reviews.show', $studentReview->id));

        Livewire::test(ReviewDetail::class, ['reviewId' => $studentReview->id])
            ->assertSee('Keberatan siswa')
            ->assertSee('Synthetic Student')
            ->assertSee('Synthetic Class')
            ->assertSee('Menunggu pemeriksaan')
            ->assertSee('10 poin');

        app(DecideReviewRequest::class)->execute($this->admin, $studentReview, 'reject', 'Catatan didukung oleh bukti.', 1, []);
        $teacherReview = $this->review($this->teacher, 'teacher_correction', 'Mohon perbaiki keterangannya.', ['operation' => 'revise', 'description' => 'Keterangan pengganti yang benar']);
        Livewire::test(ReviewQueue::class)
            ->assertSee('Synthetic Teacher')
            ->assertSee(route('admin.reviews.show', $teacherReview->id));
        Livewire::test(ReviewDetail::class, ['reviewId' => $teacherReview->id])
            ->assertSee('Koreksi guru')
            ->assertSee('Synthetic Teacher')
            ->assertSee('Synthetic Student');
    }

    public function test_admin_can_approve_a_correction_with_a_reason_and_record_before_after_snapshots(): void
    {
        $review = $this->review($this->teacher, 'teacher_correction', 'Mohon perbaiki keterangannya.', ['operation' => 'revise', 'description' => 'Usulan keterangan yang benar']);
        $page = Livewire::test(ReviewDetail::class, ['reviewId' => $review->id]);

        $page->assertSee('Sebelum pemeriksaan')
            ->assertSee('Sesudah keputusan')
            ->set('decision', 'approve')
            ->call('decide')
            ->assertHasErrors('decisionReason')
            ->set('decisionReason', 'Koreksi didukung oleh bukti sekolah.')
            ->call('decide')
            ->assertHasNoErrors()
            ->assertSee('Pemeriksaan disetujui.');

        self::assertSame('approved', $review->fresh()->status);
        self::assertSame('Usulan keterangan yang benar', $this->record->fresh()->latestRevision->description);
        self::assertSame(2, $this->record->revisions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'point_record_revised', 'resource_id' => $this->record->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review_approve', 'resource_id' => $review->id]);
    }

    public function test_admin_can_reject_with_reason_without_appending_a_revision(): void
    {
        $review = $this->review($this->student, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);

        Livewire::test(ReviewDetail::class, ['reviewId' => $review->id])
            ->set('decision', 'reject')
            ->set('decisionReason', 'Catatan awal didukung oleh bukti.')
            ->call('decide')
            ->assertHasNoErrors();

        self::assertSame('rejected', $review->fresh()->status);
        self::assertSame(1, $this->record->revisions()->count());
    }

    public function test_rejection_preview_shows_unchanged_record_instead_of_cancellation_proposal(): void
    {
        $review = $this->review($this->teacher, 'teacher_correction', 'Catatan ini perlu dibatalkan.', ['operation' => 'cancel']);
        $page = Livewire::test(ReviewDetail::class, ['reviewId' => $review->id])
            ->set('decision', 'reject')
            ->assertSee('Catatan tetap seperti saat ini karena permintaan ditolak.')
            ->assertSee('Status catatan: Berlaku')
            ->assertSee('Synthetic description')
            ->assertDontSee('Status catatan menjadi dibatalkan');

        $page->set('decisionReason', 'Catatan tetap didukung oleh bukti.')
            ->call('decide')
            ->assertHasNoErrors();

        self::assertSame('rejected', $review->fresh()->status);
        self::assertSame('active', $this->record->fresh()->latestRevision->status);
        self::assertSame('Synthetic description', $this->record->fresh()->latestRevision->description);
        self::assertSame(1, $this->record->revisions()->count());
    }

    public function test_approved_cancellation_preserves_student_teacher_and_enrollment_identity(): void
    {
        $review = $this->review($this->teacher, 'teacher_correction', 'Catatan ini perlu dibatalkan.', ['operation' => 'cancel']);
        $identity = $this->record->only(['student_id', 'teacher_id', 'enrollment_id', 'semester_id']);

        Livewire::test(ReviewDetail::class, ['reviewId' => $review->id])
            ->assertSee('Pembatalan tidak memindahkan siswa')
            ->assertSee('Status catatan menjadi dibatalkan')
            ->set('decision', 'approve')
            ->set('decisionReason', 'Catatan dibatalkan setelah pemeriksaan.')
            ->call('decide')
            ->assertHasNoErrors();

        self::assertSame('approved', $review->fresh()->status);
        self::assertSame('cancelled', $this->record->fresh()->latestRevision->status);
        self::assertSame($identity, $this->record->fresh()->only(array_keys($identity)));
    }

    public function test_stale_expected_revision_is_rendered_for_reload_without_adding_another_revision(): void
    {
        $review = $this->review($this->teacher, 'teacher_correction', 'Mohon koreksi catatan ini.', ['operation' => 'revise', 'description' => 'Usulan koreksi yang sesuai']);
        $page = Livewire::test(ReviewDetail::class, ['reviewId' => $review->id]);
        $original = $this->record->latestRevision;
        DB::table('record_revisions')->insert([
            'id' => (string) Str::uuid(),
            'point_record_id' => $this->record->id,
            'revision_number' => 2,
            'point_type_version_id' => $original->point_type_version_id,
            'points_snapshot' => $original->points_snapshot,
            'name_snapshot' => $original->name_snapshot,
            'category_snapshot' => $original->category_snapshot,
            'description' => 'Koreksi lain yang sudah tersimpan',
            'attachment_id' => $original->attachment_id,
            'status' => $original->status,
            'changed_by' => $this->admin->id,
            'reason' => 'Koreksi lain telah disimpan.',
            'created_at' => now(),
        ]);

        $page->set('decision', 'approve')
            ->set('decisionReason', 'Usulan diperiksa dengan versi lama.')
            ->call('decide')
            ->assertHasErrors('revision')
            ->assertSee('Muat ulang pemeriksaan');

        self::assertSame('pending', $review->fresh()->status);
        self::assertSame(2, $this->record->revisions()->count());
    }

    public function test_catalog_change_after_form_open_requires_latest_version_and_adds_no_revision(): void
    {
        $oldVersion = $this->rows['point_type_versions']['id'];
        $review = $this->review($this->teacher, 'teacher_correction', 'Gunakan katalog yang tersedia.', ['operation' => 'revise', 'point_type_version_id' => $oldVersion]);
        $page = Livewire::test(ReviewDetail::class, ['reviewId' => $review->id]);
        app(RevisePointType::class)->execute($this->admin, PointType::findOrFail($this->rows['point_types']['id']), [
            'name' => 'Katalog revisi terbaru',
            'points' => 15,
            'description' => 'Versi katalog terbaru yang harus dibaca ulang',
        ]);

        $page->set('decision', 'approve')
            ->set('decisionReason', 'Usulan memakai versi katalog lama.')
            ->set('pointTypeVersionId', $oldVersion)
            ->call('decide')
            ->assertHasErrors('revision')
            ->assertSee('Muat ulang pemeriksaan');

        self::assertSame('pending', $review->fresh()->status);
        self::assertSame(1, $this->record->revisions()->count());
    }

    public function test_direct_admin_cancellation_resolves_pending_review_in_one_action_without_identity_transfer(): void
    {
        $review = $this->review($this->student, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);
        $identity = $this->record->only(['student_id', 'teacher_id', 'enrollment_id', 'semester_id']);

        Livewire::test(PointRecords::class)
            ->call('beginRevision', $this->record->id)
            ->assertSet('operation', 'revise')
            ->set('operation', 'cancel')
            ->set('reason', 'Dibatalkan setelah pemeriksaan admin.')
            ->call('revise')
            ->assertHasNoErrors();

        self::assertSame('approved', $review->fresh()->status);
        self::assertSame('cancelled', $this->record->fresh()->latestRevision->status);
        self::assertSame($identity, $this->record->fresh()->only(array_keys($identity)));
    }

    public function test_private_evidence_is_streamed_through_the_admin_session_and_denied_to_an_active_teacher(): void
    {
        $attachment = Attachment::findOrFail($this->rows['attachments']['id']);
        Storage::disk('local')->put($attachment->private_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/SioAAAAASUVORK5CYII=', true));

        $this->get(route('admin.attachments.show', $attachment->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($this->teacher->fresh(), 'web')
            ->get(route('admin.attachments.show', $attachment->id))
            ->assertForbidden();
    }

    public function test_sensitive_review_decision_requires_recent_admin_confirmation_at_the_handler(): void
    {
        $review = $this->review($this->student, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);
        $this->withSession(['admin_password_confirmed_at' => now()->subMinutes(16)->timestamp]);

        Livewire::test(ReviewDetail::class, ['reviewId' => $review->id])
            ->set('decision', 'reject')
            ->set('decisionReason', 'Catatan didukung oleh bukti.')
            ->call('decide')
            ->assertHasErrors('reauthentication')
            ->assertSee('Konfirmasi password admin');

        self::assertSame('pending', $review->fresh()->status);
        self::assertSame(1, $this->record->revisions()->count());
    }

    public function test_review_revision_read_on_open_cannot_be_overridden_by_the_browser(): void
    {
        $review = $this->review($this->student, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ReviewDetail::class, ['reviewId' => $review->id])->set('expectedRevision', 2);
    }

    private function review(User $requester, string $kind, string $reason, array $changes): ReviewRequest
    {
        return app(SubmitReviewRequest::class)->execute($requester, $this->record, $kind, $reason, $changes);
    }
}

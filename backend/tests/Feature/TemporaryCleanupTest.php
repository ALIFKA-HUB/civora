<?php

namespace Tests\Feature;

use App\Console\Commands\CleanupTemporaryResources;
use App\Models\Attachment;
use App\Models\ImportBatch;
use App\Models\ReportExport;
use App\Models\StudentSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class TemporaryCleanupTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
    }

    public function test_cleanup_preserves_record_and_pending_review_evidence_and_removes_aged_orphans(): void
    {
        $recordedSelection = StudentSelection::findOrFail($this->rows['student_selections']['id']);
        $recordedSelection->update(['expires_at' => now()->subDay()]);
        $orphanSelection = StudentSelection::create([
            'teacher_id' => $recordedSelection->teacher_id, 'student_id' => $recordedSelection->student_id,
            'enrollment_id' => $recordedSelection->enrollment_id, 'semester_id' => $recordedSelection->semester_id,
            'source' => 'manual', 'expires_at' => now()->subSecond(),
        ]);

        $pendingAttachmentId = (string) Str::uuid();
        $pendingAttachment = new Attachment([
            'owner_user_id' => $this->rows['users']['id'], 'private_path' => 'evidence/'.$pendingAttachmentId.'.png',
            'mime_type' => 'image/png', 'size' => 10, 'checksum' => str_repeat('d', 64),
        ]);
        $pendingAttachment->id = $pendingAttachmentId;
        $pendingAttachment->save();
        $orphanAttachmentId = (string) Str::uuid();
        $orphanAttachment = new Attachment([
            'owner_user_id' => $this->rows['users']['id'], 'private_path' => 'evidence/'.$orphanAttachmentId.'.jpg',
            'mime_type' => 'image/jpeg', 'size' => 10, 'checksum' => str_repeat('f', 64),
        ]);
        $orphanAttachment->id = $orphanAttachmentId;
        $orphanAttachment->save();
        DB::table('attachments')->whereIn('id', [$pendingAttachment->id, $orphanAttachment->id])->update(['created_at' => now()->subDays(2)]);
        DB::table('review_requests')->where('id', $this->rows['review_requests']['id'])->update([
            'proposed_change' => json_encode(['attachment_id' => $pendingAttachment->id]),
        ]);
        Storage::disk('local')->put($pendingAttachment->private_path, 'pending evidence');
        Storage::disk('local')->put($orphanAttachment->private_path, 'staged evidence');
        self::assertTrue($orphanAttachment->fresh()->created_at->lessThan(now()->subHours(24)));
        self::assertFalse($orphanAttachment->recordRevisions()->exists());
        self::assertFalse(DB::table('review_requests')->where('status', 'pending')->whereRaw("proposed_change ->> 'attachment_id' = ?", [$orphanAttachment->id])->exists());
        self::assertSame('evidence/'.$orphanAttachment->id.'.jpg', $orphanAttachment->private_path);

        self::assertSame(0, app(CleanupTemporaryResources::class)->handle());

        self::assertDatabaseHas('student_selections', ['id' => $recordedSelection->id]);
        self::assertDatabaseMissing('student_selections', ['id' => $orphanSelection->id]);
        self::assertDatabaseHas('attachments', ['id' => $pendingAttachment->id]);
        self::assertTrue(Storage::disk('local')->exists($pendingAttachment->private_path));
        self::assertDatabaseMissing('attachments', ['id' => $orphanAttachment->id]);
        self::assertFalse(Storage::disk('local')->exists($orphanAttachment->private_path));
    }

    public function test_cleanup_expires_import_data_and_report_files_without_touching_live_claims_or_untrusted_paths(): void
    {
        $expiredImport = ImportBatch::create([
            'admin_id' => $this->rows['users']['id'], 'entity_type' => 'student', 'file_path' => 'imports/'.Str::uuid().'.csv',
            'status' => 'preview', 'row_count' => 1, 'parsed_rows' => [['nisn' => '0012345678']],
            'error_summary' => [['row' => 2, 'field' => 'name', 'message' => 'Synthetic error']],
        ]);
        $untrustedImport = ImportBatch::create([
            'admin_id' => $this->rows['users']['id'], 'entity_type' => 'student', 'file_path' => 'imports/client.csv',
            'status' => 'preview', 'row_count' => 1, 'parsed_rows' => [['nisn' => '0099999999']],
        ]);
        DB::table('import_batches')->where('id', $expiredImport->id)->update(['created_at' => now()->subDays(7)]);
        DB::table('import_batches')->where('id', $untrustedImport->id)->update(['created_at' => now()->subDays(8)]);
        $expiredReportId = (string) Str::uuid();
        $expiredReport = ReportExport::forceCreate([
            'id' => $expiredReportId, 'admin_id' => $this->rows['users']['id'], 'filters' => ['format' => 'xlsx'], 'status' => 'completed',
            'file_path' => 'reports/'.$expiredReportId.'/'.Str::uuid().'.xlsx',
            'generated_at' => now()->subDays(7), 'expires_at' => now()->subSecond(),
        ]);
        $expiredReportPath = $expiredReport->file_path;
        $liveReport = ReportExport::create([
            'admin_id' => $this->rows['users']['id'], 'filters' => ['format' => 'xlsx'], 'status' => 'processing',
            'file_path' => 'reports/'.Str::uuid().'/.'.Str::uuid().'.tmp.xlsx',
            'generation_token' => Str::uuid(), 'generation_lease_until' => now()->addMinutes(5),
        ]);
        $liveCandidatePath = 'reports/'.$liveReport->id.'/'.$liveReport->generation_token.'.xlsx';
        $orphanPath = 'reports/'.Str::uuid().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($expiredImport->file_path, 'private import');
        Storage::disk('local')->put($untrustedImport->file_path, 'untrusted path');
        Storage::disk('local')->put($expiredReport->file_path, 'expired report');
        Storage::disk('local')->put($liveReport->file_path, 'live report');
        Storage::disk('local')->put($liveCandidatePath, 'active claim candidate');
        Storage::disk('local')->put($orphanPath, 'orphan report');
        touch(Storage::disk('local')->path($liveCandidatePath), now()->subDays(8)->timestamp);
        touch(Storage::disk('local')->path($orphanPath), now()->subDays(8)->timestamp);

        self::assertSame(0, app(CleanupTemporaryResources::class)->handle());

        self::assertSame([], $expiredImport->fresh()->parsed_rows);
        self::assertSame([], $expiredImport->fresh()->error_summary);
        self::assertSame(1, $expiredImport->fresh()->row_count);
        self::assertSame('preview', $expiredImport->fresh()->status);
        self::assertFalse(Storage::disk('local')->exists($expiredImport->file_path));
        self::assertSame([], $untrustedImport->fresh()->parsed_rows);
        self::assertSame('imports/client.csv', $untrustedImport->fresh()->file_path);
        self::assertTrue(Storage::disk('local')->exists($untrustedImport->file_path));
        self::assertNull($expiredReport->fresh()->file_path);
        self::assertFalse(Storage::disk('local')->exists($expiredReportPath));
        self::assertSame($liveReport->file_path, $liveReport->fresh()->file_path);
        self::assertTrue(Storage::disk('local')->exists($liveReport->file_path));
        self::assertTrue(Storage::disk('local')->exists($liveCandidatePath));
        self::assertFalse(Storage::disk('local')->exists($orphanPath));
    }
}

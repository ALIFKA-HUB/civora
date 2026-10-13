<?php

namespace Tests\Feature;

use App\Actions\Reports\DownloadReportExport;
use App\Actions\Reports\RequestReportExport;
use App\Jobs\GeneratePointReport;
use App\Models\ReportExport;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports\PointReportQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class PointReportTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Student $student;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->student = Student::findOrFail($this->rows['students']['id']);
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        Storage::fake('local');
        Queue::fake();
    }

    public function test_report_uses_historical_enrollment_and_latest_effective_revision(): void
    {
        $oldEnrollment = $this->rows['enrollments']['id'];
        $classId = DB::table('enrollments')->where('id', $oldEnrollment)->value('classroom_id');
        $majorId = DB::table('classrooms')->where('id', $classId)->value('major_id');
        $newClassId = (string) Str::uuid();
        DB::table('classrooms')->insert(['id' => $newClassId, 'academic_year_id' => $this->semester->academic_year_id, 'major_id' => $majorId, 'code' => 'XI-1', 'name' => 'Current Class', 'grade' => 11]);
        $newEnrollment = (string) Str::uuid();
        DB::table('enrollments')->insert(['id' => $newEnrollment, 'student_id' => $this->student->id, 'semester_id' => $this->semester->id, 'classroom_id' => $newClassId, 'valid_from' => '2026-12-01 00:00:00+07']);

        $oldRecord = $this->record($oldEnrollment, 'Old Class', 'Old Major', 'violation', 12, 'active', 'Before move');
        $this->revise($oldRecord, 2, 10, 'active');
        $this->record($newEnrollment, 'Current Class', 'Old Major', 'violation', 5, 'active', 'After move');
        $this->record($oldEnrollment, 'Old Class', 'Old Major', 'violation', 40, 'cancelled', 'Cancelled event');

        $filters = ['semester_id' => $this->semester->id, 'classroom_id' => $classId, 'category' => 'violation'];
        $report = app(PointReportQuery::class)->build($filters);

        self::assertSame(10, $report['summary']['violation']);
        self::assertSame(0, $report['summary']['achievement']);
        self::assertCount(2, $report['rows']);
        $activeRow = collect($report['rows'])->firstWhere('status', 'active');
        self::assertSame('Old Class', $activeRow['class']);
        self::assertSame('0012345678', $activeRow['nisn']);
        self::assertSame('cancelled', collect($report['rows'])->firstWhere('status', 'cancelled')['status']);
    }

    public function test_export_generates_private_xlsx_and_pdf_from_the_same_filtered_dataset(): void
    {
        $enrollmentId = $this->rows['enrollments']['id'];
        DB::table('users')->where('id', $this->student->user_id)->update(['name' => '=Student Formula']);
        $this->record($enrollmentId, 'Synthetic Class', 'Synthetic Major', 'violation', 3, 'active', '=2+2 formula');
        $this->record($enrollmentId, 'Synthetic Class', 'Synthetic Major', 'achievement', 7, 'active', '+SUM(A1:A2) formula');
        $this->record($enrollmentId, 'Synthetic Class', 'Synthetic Major', 'achievement', 1, 'active', '-1+1 formula');
        $this->record($enrollmentId, 'Synthetic Class', 'Synthetic Major', 'achievement', 1, 'active', '@SUM(A1:A2) formula');
        $filters = ['semester_id' => $this->semester->id];
        $screen = app(PointReportQuery::class)->build($filters);

        $xlsx = app(RequestReportExport::class)->execute($this->admin, $filters, 'xlsx');
        $pdf = app(RequestReportExport::class)->execute($this->admin, $filters, 'pdf');
        self::assertSame('queued', $xlsx->status);
        Queue::assertPushed(GeneratePointReport::class, fn (GeneratePointReport $job): bool => in_array($job->reportExportId, [$xlsx->id, $pdf->id], true));
        self::assertSame(2, Queue::pushed(GeneratePointReport::class)->count());
        (new GeneratePointReport($xlsx->id))->handle();
        (new GeneratePointReport($pdf->id))->handle();

        $xlsx = $xlsx->fresh();
        $pdf = $pdf->fresh();
        self::assertSame('completed', $xlsx->status);
        self::assertSame('completed', $pdf->status);
        self::assertSame($screen['summary']['achievement'], $this->xlsxSummary($xlsx, 'achievement'));
        self::assertSame($screen['summary']['violation'], $this->xlsxSummary($xlsx, 'violation'));
        self::assertSame('0012345678', $this->xlsxStudentId($xlsx));
        self::assertSame('=Student Formula', $this->xlsxCellValue($xlsx, 'C2'));
        self::assertSame(DataType::TYPE_STRING, $this->xlsxCellType($xlsx, 'C2'));
        self::assertSame(DataType::TYPE_NUMERIC, $this->xlsxCellType($xlsx, 'J2'));
        self::assertSame('Asia/Jakarta', $this->xlsxSummaryCell($xlsx, 'B4'));
        self::assertStringContainsString('Semester: Ganjil', $this->xlsxSummaryCell($xlsx, 'B6'));
        $xlsxDescriptions = $this->xlsxColumnValues($xlsx, 'L');
        foreach (['=2+2 formula', '+SUM(A1:A2) formula', '-1+1 formula', '@SUM(A1:A2) formula'] as $formulaLikeText) {
            self::assertContains($formulaLikeText, $xlsxDescriptions);
        }
        self::assertTrue(Storage::disk('local')->exists($xlsx->file_path));
        self::assertTrue(Storage::disk('local')->exists($pdf->file_path));
        self::assertEquals(7, $xlsx->generated_at->diffInDays($xlsx->expires_at));
        $this->assertPdfContains($pdf, 'Synthetic School');
        $this->assertPdfContains($pdf, '0012345678');
        $this->assertPdfContains($pdf, 'Semester');
        $this->assertPdfContains($pdf, (string) $screen['summary']['achievement']);
        $this->assertPdfContains($pdf, '3');
    }

    public function test_filters_are_whitelisted_and_reject_unknown_ids_or_categories(): void
    {
        foreach ([
            ['semester_id' => (string) Str::uuid()],
            ['semester_id' => $this->semester->id, 'classroom_id' => (string) Str::uuid()],
            ['semester_id' => $this->semester->id, 'category' => 'net'],
            ['semester_id' => $this->semester->id, 'unexpected' => 'value'],
        ] as $filters) {
            try {
                app(PointReportQuery::class)->build($filters);
                self::fail('Expected report filter validation failure.');
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_only_active_admin_owner_can_download_completed_report(): void
    {
        $export = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id], 'status' => 'completed', 'file_path' => 'reports/private.xlsx', 'generated_at' => now(), 'expires_at' => now()->addDays(7)]);
        Storage::disk('local')->put($export->file_path, 'report');
        self::assertInstanceOf(StreamedResponse::class, app(DownloadReportExport::class)->execute($this->admin, $export->id));

        try {
            app(DownloadReportExport::class)->execute($this->teacher, $export->id);
            self::fail('Expected teacher download denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $export->update(['expires_at' => now()->subSecond()]);
        try {
            app(DownloadReportExport::class)->execute($this->admin, $export->id);
            self::fail('Expected expired report denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $otherAdmin = User::create(['role' => 'admin', 'name' => 'Other Admin', 'password_hash' => 'synthetic-hash', 'account_status' => 'active']);
        try {
            app(DownloadReportExport::class)->execute($otherAdmin, $export->id);
            self::fail('Expected owner download denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $this->admin->update(['account_status' => 'inactive']);
        try {
            app(DownloadReportExport::class)->execute($this->admin, $export->id);
            self::fail('Expected inactive admin download denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_export_request_requires_a_fresh_active_admin_and_failure_is_recorded(): void
    {
        try {
            app(RequestReportExport::class)->execute($this->teacher, ['semester_id' => $this->semester->id], 'xlsx');
            self::fail('Expected teacher export request denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $this->admin->update(['account_status' => 'inactive']);
        try {
            app(RequestReportExport::class)->execute($this->admin, ['semester_id' => $this->semester->id], 'xlsx');
            self::fail('Expected inactive admin export request denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $export = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'xlsx'], 'status' => 'queued']);
        $export->update(['filters' => ['semester_id' => $this->semester->id, 'category' => 'net', 'format' => 'xlsx']]);
        try {
            (new GeneratePointReport($export->id))->handle();
            self::fail('Expected invalid stored filter to fail generation.');
        } catch (ValidationException) {
            self::assertSame('failed', $export->fresh()->status);
            self::assertNull($export->fresh()->file_path);
            self::assertSame([], Storage::disk('local')->allFiles());
        }
    }

    private function record(string $enrollmentId, string $class, string $major, string $category, int $points, string $status, string $description): object
    {
        $suffix = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $typeId = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $typeId, 'code' => substr(str_replace('-', '', $suffix), 0, 20), 'category' => $category]);
        DB::table('point_type_versions')->insert(['id' => $versionId, 'point_type_id' => $typeId, 'version' => 1, 'name' => "Synthetic $category", 'description' => 'Synthetic report fixture', 'points' => $points, 'created_by' => $this->admin->id, 'created_at' => now()]);
        $selectionId = (string) Str::uuid();
        DB::table('student_selections')->insert(['id' => $selectionId, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $this->student->id, 'enrollment_id' => $enrollmentId, 'semester_id' => $this->semester->id, 'source' => 'manual', 'expires_at' => now()->addMinutes(5)]);
        $recordId = (string) Str::uuid();
        DB::table('point_records')->insert(['id' => $recordId, 'student_id' => $this->student->id, 'teacher_id' => DB::table('teachers')->value('id'), 'enrollment_id' => $enrollmentId, 'semester_id' => $this->semester->id, 'selection_id' => $selectionId, 'source' => 'manual', 'occurred_at' => now(), 'class_snapshot' => $class, 'major_snapshot' => $major, 'idempotency_key' => $suffix, 'request_hash' => str_repeat('d', 64)]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $recordId, 'revision_number' => 1, 'point_type_version_id' => $versionId, 'points_snapshot' => $points, 'name_snapshot' => "Synthetic $category", 'category_snapshot' => $category, 'description' => $description, 'status' => $status, 'changed_by' => $this->teacher->id, 'reason' => 'Initial synthetic report fixture', 'created_at' => now()]);

        return (object) ['id' => $recordId, 'version_id' => $versionId, 'category' => $category];
    }

    private function revise(object $record, int $revision, int $points, string $status): void
    {
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $record->id, 'revision_number' => $revision, 'point_type_version_id' => $record->version_id, 'points_snapshot' => $points, 'name_snapshot' => "Synthetic $record->category", 'category_snapshot' => $record->category, 'description' => 'Revised report fixture', 'status' => $status, 'changed_by' => $this->admin->id, 'reason' => 'Synthetic report correction', 'created_at' => now()]);
    }

    private function xlsxSummary(ReportExport $export, string $category): int
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));

        return (int) $book->getSheetByName('Summary')->getCell($category === 'achievement' ? 'B9' : 'B10')->getValue();
    }

    private function xlsxStudentId(ReportExport $export): string
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));

        return (string) $book->getSheetByName('Details')->getCell('B2')->getValue();
    }

    private function xlsxCellType(ReportExport $export, string $cell): string
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));

        return $book->getSheetByName('Details')->getCell($cell)->getDataType();
    }

    private function xlsxCellValue(ReportExport $export, string $cell): string
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));

        return (string) $book->getSheetByName('Details')->getCell($cell)->getValue();
    }

    private function xlsxSummaryCell(ReportExport $export, string $cell): string
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));

        return (string) $book->getSheetByName('Summary')->getCell($cell)->getValue();
    }

    private function xlsxColumnValues(ReportExport $export, string $column): array
    {
        $book = IOFactory::load(Storage::disk('local')->path($export->file_path));
        $sheet = $book->getSheetByName('Details');

        return collect(range(2, $sheet->getHighestRow()))
            ->map(fn (int $row) => (string) $sheet->getCell($column.$row)->getValue())
            ->all();
    }

    private function pdfText(ReportExport $export): string
    {
        $pdf = Storage::disk('local')->get($export->file_path);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded !== false) {
                $text .= $decoded;
            }
        }

        return $text;
    }

    private function assertPdfContains(ReportExport $export, string $expected): void
    {
        $utf16be = iconv('UTF-8', 'UTF-16BE', $expected);
        self::assertNotFalse($utf16be);
        self::assertStringContainsString(bin2hex($utf16be), bin2hex($this->pdfText($export)));
    }
}

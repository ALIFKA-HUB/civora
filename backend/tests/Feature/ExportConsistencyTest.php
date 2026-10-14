<?php

namespace Tests\Feature;

use App\Actions\Reports\RequestReportExport;
use App\Jobs\GeneratePointReport;
use App\Models\ReportExport;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports\PointReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class ExportConsistencyTest extends TestCase
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

    public function test_xlsx_and_pdf_match_the_effective_historical_report_snapshot(): void
    {
        $longName = '=Synthetic Student Name '.str_repeat('long-name-segment ', 5);
        DB::table('users')->where('id', $this->student->user_id)->update(['name' => $longName]);

        $firstEnrollment = $this->rows['enrollments']['id'];
        $secondClassId = (string) Str::uuid();
        DB::table('classrooms')->insert([
            'id' => $secondClassId,
            'academic_year_id' => $this->semester->academic_year_id,
            'major_id' => DB::table('classrooms')->where('id', $this->rows['classrooms']['id'])->value('major_id'),
            'code' => 'X-2', 'name' => 'Historical Class with a Long Name '.str_repeat('B', 48), 'grade' => 10,
        ]);
        $secondEnrollment = (string) Str::uuid();
        DB::table('enrollments')->insert([
            'id' => $secondEnrollment, 'student_id' => $this->student->id, 'semester_id' => $this->semester->id,
            'classroom_id' => $secondClassId, 'valid_from' => '2026-12-02 00:00:00+07', 'valid_until' => '2026-12-31 23:59:59+07',
        ]);

        for ($number = 1; $number <= 120; $number++) {
            $isSecondClass = $number > 60;
            $occurredAt = $isSecondClass
                ? now()->parse('2026-12-02 08:00:00 Asia/Jakarta')->addMinutes($number)->utc()
                : now()->parse('2026-08-01 08:00:00 Asia/Jakarta')->addMinutes($number)->utc();
            $description = match ($number) {
                1 => '=2+2 formula-like text '.str_repeat('long description ', 7),
                2 => '+SUM(A1:A2) formula-like text '.str_repeat('long description ', 7),
                3 => '-1+1 formula-like text '.str_repeat('long description ', 7),
                4 => '@SUM(A1:A2) formula-like text '.str_repeat('long description ', 7),
                5 => 'Unbroken description '.str_repeat('D', 80),
                120 => 'Very long description '.str_repeat('long description ', 18),
                default => 'Synthetic detailed report note '.str_repeat('long description ', 7),
            };
            $category = $number % 2 === 0 ? 'achievement' : 'violation';
            $status = $number % 15 === 0 ? 'cancelled' : 'active';
            $this->record(
                $isSecondClass ? $secondEnrollment : $firstEnrollment,
                $isSecondClass ? 'Historical Class with a Long Name '.str_repeat('B', 48) : 'Synthetic Class',
                $category,
                5,
                $status,
                $description,
                $occurredAt,
                $number % 10 === 0 && $number % 15 !== 0,
            );
        }

        $secondSemesterId = (string) Str::uuid();
        DB::table('semesters')->insert([
            'id' => $secondSemesterId, 'academic_year_id' => $this->semester->academic_year_id,
            'name' => 'Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'draft',
        ]);
        $secondSemesterEnrollment = (string) Str::uuid();
        DB::table('enrollments')->insert([
            'id' => $secondSemesterEnrollment, 'student_id' => $this->student->id, 'semester_id' => $secondSemesterId,
            'classroom_id' => DB::table('classrooms')->where('id', $this->rows['classrooms']['id'])->value('id'),
            'valid_from' => '2027-01-01 00:00:00+07', 'valid_until' => '2027-06-30 23:59:59+07',
        ]);
        $this->record($secondSemesterEnrollment, 'Synthetic Class', 'achievement', 99, 'active', 'Other semester row', now()->parse('2027-01-02 08:00:00 Asia/Jakarta')->utc(), false, $secondSemesterId);

        $filters = ['semester_id' => $this->semester->id];
        $screen = app(PointReportQuery::class)->build($filters);
        self::assertCount(121, $screen['rows']);
        self::assertSame(['achievement' => 306, 'violation' => 280], $screen['summary']);

        $xlsx = app(RequestReportExport::class)->execute($this->admin, $filters, 'xlsx');
        $pdf = app(RequestReportExport::class)->execute($this->admin, $filters, 'pdf');
        self::assertSame(2, Queue::pushed(GeneratePointReport::class)->count());
        (new GeneratePointReport($xlsx->id))->handle();
        (new GeneratePointReport($pdf->id))->handle();
        $xlsx = $xlsx->fresh();
        $pdf = $pdf->fresh();

        self::assertSame('completed', $xlsx->status);
        self::assertSame('completed', $pdf->status);
        $book = IOFactory::load(Storage::disk('local')->path($xlsx->file_path));
        $summary = $book->getSheetByName('Summary');
        $details = $book->getSheetByName('Details');
        self::assertSame(306, (int) $summary->getCell('B9')->getValue());
        self::assertSame(280, (int) $summary->getCell('B10')->getValue());
        self::assertSame(122, $details->getHighestRow());
        self::assertSame('0012345678', $details->getCell('B2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $details->getCell('B2')->getDataType());
        self::assertSame($longName, $details->getCell('C2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $details->getCell('C2')->getDataType());
        self::assertSame(DataType::TYPE_NUMERIC, $details->getCell('J2')->getDataType());

        foreach ($screen['rows'] as $index => $row) {
            $line = $index + 2;
            $expected = [$row['id'], $row['nisn'], $row['student'], $row['semester'], $row['occurred_at'], $row['class'], $row['major'], $row['category'], $row['point_name'], $row['points'], $row['status'], $row['description']];
            foreach ($expected as $column => $value) {
                $cell = $details->getCell([$column + 1, $line]);
                self::assertSame($value, $column === 9 ? (int) $cell->getValue() : (string) $cell->getValue(), "XLSX row {$line}, column {$column} differs from PointReportQuery.");
                if ($column !== 9) {
                    self::assertSame(DataType::TYPE_STRING, $cell->getDataType(), "XLSX text cell {$line}, column {$column} must remain text.");
                }
            }
        }

        foreach ([
            '=2+2', '+SUM', '-1+1', '@SUM',
        ] as $formulaLikeText) {
            $rowIndex = null;
            foreach ($screen['rows'] as $candidateIndex => $candidate) {
                if (str_starts_with($candidate['description'], $formulaLikeText)) {
                    $rowIndex = $candidateIndex;
                    break;
                }
            }
            self::assertNotNull($rowIndex);
            $cell = $details->getCell('L'.($rowIndex + 2));
            self::assertSame($screen['rows'][$rowIndex]['description'], $cell->getValue());
            self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
        }

        $pdfText = $this->pdfText($pdf);
        foreach (['Synthetic School', 'Semester: Ganjil', 'Timezone: Asia/Jakarta', 'Filter:', '0012345678', '=Synthetic', 'Student Name', 'long-name-', 'segment', '=2+2', '+SUM', '-1+1', '@SUM', 'Historical Class', str_repeat('D', 10)] as $expectedText) {
            $this->assertPdfContains($pdfText, $expectedText);
        }
        self::assertSame(121, substr_count(bin2hex($pdfText), bin2hex(iconv('UTF-8', 'UTF-16BE', '0012345678'))));
        $header = bin2hex(iconv('UTF-8', 'UTF-16BE', 'NISN'));
        self::assertGreaterThan(1, substr_count(bin2hex($pdfText), $header), 'PDF detail columns must repeat their header on later pages.');
        $this->assertPdfContains($pdfText, '1 /');
        $this->assertPdfContains($pdfText, '2 /');
        $this->assertPdfContains($pdfText, '306');
        $this->assertPdfContains($pdfText, '280');
        self::assertStringNotContainsString('synthetic-hash', $pdfText);
        self::assertStringNotContainsString('encrypted-fixture', $pdfText);
        self::assertStringNotContainsString('synthetic-device-token', $pdfText);
        $xlsxText = '';
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $values) {
                $xlsxText .= implode("\n", array_map('strval', $values))."\n";
            }
        }
        foreach (['synthetic-hash', 'encrypted-fixture', 'synthetic-device-token'] as $secret) {
            self::assertStringNotContainsString($secret, $xlsxText);
        }

        $qaDirectory = dirname(base_path()).'/.runtime/qa/exports';
        if (! is_dir($qaDirectory) && ! mkdir($qaDirectory, 0700, true) && ! is_dir($qaDirectory)) {
            self::fail('Could not create the private export QA directory.');
        }
        self::assertNotFalse(copy(Storage::disk('local')->path($xlsx->file_path), $qaDirectory.'/i02-point-report.xlsx'));
        self::assertNotFalse(copy(Storage::disk('local')->path($pdf->file_path), $qaDirectory.'/i02-point-report.pdf'));
    }

    private function record(string $enrollmentId, string $class, string $category, int $points, string $status, string $description, $occurredAt, bool $revise = false, ?string $semesterId = null): void
    {
        $suffix = (string) Str::uuid();
        $typeId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $typeId, 'code' => substr(str_replace('-', '', $suffix), 0, 20), 'category' => $category]);
        DB::table('point_type_versions')->insert([
            'id' => $versionId, 'point_type_id' => $typeId, 'version' => 1, 'name' => 'Synthetic Point Type '.str_repeat('description ', 5),
            'description' => 'Synthetic type description', 'points' => $points, 'created_by' => $this->admin->id, 'created_at' => now(),
        ]);
        $selectionId = (string) Str::uuid();
        DB::table('student_selections')->insert([
            'id' => $selectionId, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $this->student->id,
            'enrollment_id' => $enrollmentId, 'semester_id' => $semesterId ?? $this->semester->id,
            'source' => 'manual', 'expires_at' => now()->addMinutes(5),
        ]);
        $recordId = (string) Str::uuid();
        DB::table('point_records')->insert([
            'id' => $recordId, 'student_id' => $this->student->id, 'teacher_id' => DB::table('teachers')->value('id'),
            'enrollment_id' => $enrollmentId, 'semester_id' => $semesterId ?? $this->semester->id, 'selection_id' => $selectionId,
            'source' => 'manual', 'occurred_at' => $occurredAt, 'class_snapshot' => $class,
            'major_snapshot' => 'Synthetic Major', 'idempotency_key' => $suffix, 'request_hash' => str_repeat('d', 64),
        ]);
        DB::table('record_revisions')->insert([
            'id' => (string) Str::uuid(), 'point_record_id' => $recordId, 'revision_number' => 1, 'point_type_version_id' => $versionId,
            'points_snapshot' => $points, 'name_snapshot' => 'Synthetic Point Type '.str_repeat('description ', 5),
            'category_snapshot' => $category, 'description' => $description, 'status' => $status, 'changed_by' => $this->teacher->id,
            'reason' => 'Synthetic export fixture', 'created_at' => now(),
        ]);
        if ($revise) {
            DB::table('record_revisions')->insert([
                'id' => (string) Str::uuid(), 'point_record_id' => $recordId, 'revision_number' => 2, 'point_type_version_id' => $versionId,
                'points_snapshot' => 7, 'name_snapshot' => 'Revised Synthetic Point Type '.str_repeat('description ', 5),
                'category_snapshot' => $category, 'description' => 'Revised effective description '.str_repeat('corrected detail ', 18),
                'status' => 'active', 'changed_by' => $this->admin->id, 'reason' => 'Synthetic export revision', 'created_at' => now(),
            ]);
        }
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

    private function assertPdfContains(string $pdfText, string $expected): void
    {
        $utf16be = iconv('UTF-8', 'UTF-16BE', $expected);
        self::assertNotFalse($utf16be);
        self::assertTrue(str_contains(bin2hex($pdfText), bin2hex($utf16be)), 'PDF export is missing expected text: '.substr($expected, 0, 80));
    }
}

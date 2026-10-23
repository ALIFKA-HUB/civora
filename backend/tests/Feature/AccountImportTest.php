<?php

namespace Tests\Feature;

use App\Actions\Imports\CommitAccountsImport;
use App\Actions\Imports\PreviewAccountsImport;
use App\Models\Semester;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AccountImportTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private string $semesterId;

    private string $classroomId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::create([
            'role' => 'admin', 'name' => 'Synthetic Import Admin', 'password_hash' => 'synthetic-password',
            'account_status' => 'active', 'must_change_password' => false,
        ]);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'name' => 'Synthetic School', 'timezone' => 'Asia/Jakarta']);
        $year = (string) Str::uuid();
        $this->semesterId = (string) Str::uuid();
        $major = (string) Str::uuid();
        $this->classroomId = (string) Str::uuid();
        DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        DB::table('semesters')->insert(['id' => $this->semesterId, 'academic_year_id' => $year, 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        DB::table('majors')->insert(['id' => $major, 'code' => 'RPL', 'name' => 'Rekayasa Perangkat Lunak', 'active' => true]);
        DB::table('classrooms')->insert(['id' => $this->classroomId, 'academic_year_id' => $year, 'major_id' => $major, 'code' => 'X-1', 'name' => 'X RPL 1', 'grade' => 10, 'active' => true]);
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(9, 0));
    }

    public function test_student_import_preserves_text_identifiers_and_commits_accounts_and_placements_atomically(): void
    {
        $file = $this->csv("name,nisn,major_code,class_code\nSynthetic Student,0012345678,RPL,X-1\n");
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $file, 'student', $this->semester());

        self::assertSame(0, DB::table('users')->where('role', 'student')->count());
        self::assertSame(0, DB::table('temporary_credentials')->count());
        self::assertSame(1, $batch->row_count);
        self::assertSame([], $batch->error_summary);

        $confirmed = app(CommitAccountsImport::class)->execute($this->admin, $batch);
        $student = DB::table('students')->where('nisn', '0012345678')->first();
        self::assertSame('confirmed', $confirmed->status);
        self::assertSame('0012345678', $student->nisn);
        self::assertSame(1, DB::table('enrollments')->where('student_id', $student->id)->count());
        self::assertSame(1, DB::table('temporary_credentials')->where('user_id', $student->user_id)->count());
        self::assertSame(1, DB::table('audit_logs')->where('resource_id', $student->user_id)->where('action', 'account_created')->count());

        $this->travelTo(CarbonImmutable::parse($confirmed->created_at)->addDays(7)->addSecond());
        $retried = app(CommitAccountsImport::class)->execute($this->admin, $confirmed);
        self::assertSame('confirmed', $retried->status);
        self::assertSame(1, DB::table('users')->where('role', 'student')->count());
        self::assertSame(1, DB::table('enrollments')->count());
    }

    public function test_preview_can_commit_immediately_before_its_seven_day_expiry(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0088888888,RPL,X-1\n"), 'student', $this->semester());
        $this->travelTo(CarbonImmutable::parse($batch->created_at)->addDays(7)->subSecond());

        $confirmed = app(CommitAccountsImport::class)->execute($this->admin, $batch);

        self::assertSame('confirmed', $confirmed->status);
        self::assertSame(1, DB::table('users')->where('role', 'student')->count());
    }

    public function test_preview_expires_at_seven_days_without_creating_accounts(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0088888889,RPL,X-1\n"), 'student', $this->semester());
        $this->travelTo(CarbonImmutable::parse($batch->created_at)->addDays(7));

        $expired = app(CommitAccountsImport::class)->execute($this->admin, $batch);

        self::assertSame('failed', $expired->status);
        self::assertSame('batch', $expired->error_summary[0]['field']);
        self::assertStringContainsString('kedaluwarsa', mb_strtolower($expired->error_summary[0]['message']));
        self::assertNoImportedAccounts();
    }

    public function test_changed_academic_year_dates_fail_with_row_semester_error_before_account_creation(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0088888890,RPL,X-1\n"), 'student', $this->semester());
        DB::table('academic_years')->where('id', $this->semester()->academic_year_id)->update(['end_date' => '2026-07-31']);

        $failed = app(CommitAccountsImport::class)->execute($this->admin, $batch);

        self::assertSame('failed', $failed->status);
        self::assertSame(2, $failed->error_summary[0]['row']);
        self::assertSame('semester', $failed->error_summary[0]['field']);
        self::assertNoImportedAccounts();
    }

    public function test_active_semester_expired_after_preview_fails_with_row_error_before_account_creation(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0088888891,RPL,X-1\n"), 'student', $this->semester());
        DB::table('semesters')->where('id', $this->semesterId)->update(['end_date' => '2026-07-31']);

        $failed = app(CommitAccountsImport::class)->execute($this->admin, $batch);

        self::assertSame('failed', $failed->status);
        self::assertSame(2, $failed->error_summary[0]['row']);
        self::assertSame('semester', $failed->error_summary[0]['field']);
        self::assertNoImportedAccounts();
    }

    public function test_duplicate_rows_make_the_preview_uncommittable_and_create_nothing(): void
    {
        $file = $this->csv("name,nisn,major_code,class_code\nFirst Student,0099999999,RPL,X-1\nSecond Student,0099999999,RPL,X-1\n");
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $file, 'student', $this->semester());

        self::assertNotEmpty($batch->error_summary);
        try {
            app(CommitAccountsImport::class)->execute($this->admin, $batch);
            self::fail('An invalid preview must not commit.');
        } catch (ValidationException) {
            self::assertSame(0, DB::table('users')->where('role', 'student')->count());
            self::assertSame(0, DB::table('login_identifiers')->where('role', 'student')->count());
        }
    }

    public function test_teacher_without_nip_gets_a_generated_login_code(): void
    {
        $file = $this->csv("name\nSynthetic Teacher\n", 'teachers.csv');
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $file, 'teacher', null);
        $confirmed = app(CommitAccountsImport::class)->execute($this->admin, $batch);
        $teacher = DB::table('teachers')->first();

        self::assertSame('confirmed', $confirmed->status);
        self::assertNull($teacher->nip);
        self::assertNotSame('', $teacher->teacher_code);
        self::assertSame(1, DB::table('login_identifiers')->where('role', 'teacher')->where('kind', 'teacher_code')->count());
    }

    public function test_other_admin_cannot_commit_a_batch(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0077777777,RPL,X-1\n"), 'student', $this->semester());
        $other = User::create(['role' => 'admin', 'name' => 'Other Admin', 'password_hash' => 'synthetic-password', 'account_status' => 'active', 'must_change_password' => false]);

        try {
            app(CommitAccountsImport::class)->execute($other, $batch);
            self::fail('A different admin must not commit the batch.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('users')->where('role', 'student')->count());
        }
    }

    public function test_deactivated_class_after_preview_fails_without_partial_accounts(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0066666666,RPL,X-1\n"), 'student', $this->semester());
        DB::table('classrooms')->where('id', $this->classroomId)->update(['active' => false]);

        $failed = app(CommitAccountsImport::class)->execute($this->admin, $batch);
        self::assertSame('failed', $failed->status);
        self::assertNotEmpty($failed->error_summary);
        self::assertSame(0, DB::table('users')->where('role', 'student')->count());
        self::assertSame(0, DB::table('enrollments')->count());
    }

    public function test_unknown_headers_and_formula_cells_are_rejected(): void
    {
        $unknown = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code,password\nStudent,0011111111,RPL,X-1,secret\n"), 'student', $this->semester());
        self::assertNotEmpty($unknown->error_summary);
        try {
            app(CommitAccountsImport::class)->execute($this->admin, $unknown);
            self::fail('An invalid preview must not be confirmable.');
        } catch (ValidationException) {
            self::assertSame('preview', $unknown->fresh()->status);
            self::assertNotEmpty($unknown->fresh()->error_summary);
        }

        $formula = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\n=HYPERLINK(\"https://example.invalid\"),0011111112,RPL,X-1\n"), 'student', $this->semester());
        self::assertNotEmpty($formula->error_summary);
    }

    public function test_xlsx_text_identifier_is_preserved_and_numeric_identifier_is_rejected(): void
    {
        $textBatch = app(PreviewAccountsImport::class)->execute($this->admin, $this->xlsx('0012345678', true), 'student', $this->semester());
        self::assertSame([], $textBatch->error_summary);
        self::assertSame('0012345678', $textBatch->parsed_rows[0]['nisn']);

        $numericBatch = app(PreviewAccountsImport::class)->execute($this->admin, $this->xlsx('12345678', false), 'student', $this->semester());
        self::assertNotEmpty($numericBatch->error_summary);
        $formulaBatch = app(PreviewAccountsImport::class)->execute($this->admin, $this->xlsx('0011111111', true, true), 'student', $this->semester());
        self::assertNotEmpty($formulaBatch->error_summary);
        self::assertSame(0, DB::table('users')->where('role', 'student')->count());
    }

    public function test_csv_supports_quoted_multiline_names(): void
    {
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\n\"Synthetic\nStudent\",0033333333,RPL,X-1\n"), 'student', $this->semester());

        self::assertSame("Synthetic\nStudent", $batch->parsed_rows[0]['name']);
        self::assertSame([], $batch->error_summary);
    }

    public function test_existing_identifier_and_invalid_class_are_reported_in_preview(): void
    {
        $existing = User::create(['role' => 'student', 'name' => 'Existing Student', 'password_hash' => 'synthetic-password', 'account_status' => 'pending_activation', 'must_change_password' => true]);
        DB::table('students')->insert(['id' => (string) Str::uuid(), 'user_id' => $existing->id, 'nisn' => '0012345678', 'education_status' => 'active']);
        DB::table('login_identifiers')->insert(['id' => (string) Str::uuid(), 'user_id' => $existing->id, 'role' => 'student', 'kind' => 'nisn', 'normalized_value' => '0012345678']);
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nStudent,0012345678,RPL,DOES-NOT-EXIST\n"), 'student', $this->semester());

        self::assertCount(2, $batch->error_summary);
        self::assertSame(1, DB::table('users')->where('role', 'student')->count());
    }

    public function test_file_size_and_row_limits_are_enforced(): void
    {
        try {
            app(PreviewAccountsImport::class)->execute($this->admin, $this->csv(str_repeat('x', 10 * 1024 * 1024 + 1)), 'student', $this->semester());
            self::fail('Oversized file must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, DB::table('import_batches')->count());
        }

        $content = "name,nisn,major_code,class_code\n".str_repeat("Student,0012345678,RPL,X-1\n", 5001);
        try {
            app(PreviewAccountsImport::class)->execute($this->admin, $this->csv($content), 'student', $this->semester());
            self::fail('More than 5,000 data rows must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, DB::table('import_batches')->count());
        }
    }

    public function test_csv_column_and_record_limits_are_enforced(): void
    {
        foreach ([implode(',', array_fill(0, 17, 'header')), str_repeat('x', 65537)] as $contents) {
            try {
                app(PreviewAccountsImport::class)->execute($this->admin, $this->csv($contents), 'student', $this->semester());
                self::fail('Oversized CSV structure must be rejected.');
            } catch (ValidationException) {
                self::assertSame(0, DB::table('import_batches')->count());
            }
        }
    }

    public function test_failed_second_account_rolls_back_the_entire_batch(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_synthetic_import_user ON users');
        DB::unprepared('DROP FUNCTION IF EXISTS fail_synthetic_import_user()');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION fail_synthetic_import_user() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.name = 'Fail Student' THEN RAISE EXCEPTION 'synthetic import failure'; END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::unprepared('CREATE TRIGGER fail_synthetic_import_user BEFORE INSERT ON users FOR EACH ROW EXECUTE FUNCTION fail_synthetic_import_user()');
        $batch = app(PreviewAccountsImport::class)->execute($this->admin, $this->csv("name,nisn,major_code,class_code\nFirst Student,0012345678,RPL,X-1\nFail Student,0098765432,RPL,X-1\n"), 'student', $this->semester());
        self::assertSame([], $batch->error_summary);

        try {
            app(CommitAccountsImport::class)->execute($this->admin, $batch);
            self::fail('Synthetic mid-batch database failure must abort commit.');
        } catch (ValidationException) {
            self::assertSame(0, DB::table('users')->where('role', 'student')->count());
            self::assertSame(0, DB::table('login_identifiers')->where('role', 'student')->count());
            self::assertSame(0, DB::table('temporary_credentials')->count());
            self::assertSame(0, DB::table('enrollments')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'account_created')->count());
        }
    }

    private function semester(): Semester
    {
        return Semester::findOrFail($this->semesterId);
    }

    private function assertNoImportedAccounts(): void
    {
        self::assertSame(0, DB::table('users')->where('role', 'student')->count());
        self::assertSame(0, DB::table('login_identifiers')->where('role', 'student')->count());
        self::assertSame(0, DB::table('temporary_credentials')->count());
        self::assertSame(0, DB::table('enrollments')->count());
        self::assertSame(0, DB::table('audit_logs')->count());
    }

    private function csv(string $contents, string $name = 'students.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function xlsx(string $nisn, bool $asText, bool $formula = false): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['name', 'nisn', 'major_code', 'class_code'] as $index => $header) {
            $sheet->setCellValue(chr(65 + $index).'1', $header);
        }
        $sheet->setCellValue('A2', $formula ? '=HYPERLINK("https://example.invalid","Synthetic Student")' : 'Synthetic Student');
        if ($asText) {
            $sheet->setCellValueExplicit('B2', $nisn, DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue('B2', (int) $nisn);
        }
        $sheet->setCellValue('C2', 'RPL');
        $sheet->setCellValue('D2', 'X-1');
        $path = tempnam(sys_get_temp_dir(), 'civora-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);
        $spreadsheet->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('students.xlsx', $contents);
    }
}

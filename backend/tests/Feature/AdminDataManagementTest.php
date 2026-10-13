<?php

namespace Tests\Feature;

use App\Livewire\Admin\Academic;
use App\Livewire\Admin\AccountsImport;
use App\Livewire\Admin\StudentDetail;
use App\Livewire\Admin\Students;
use App\Livewire\Admin\Teachers;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\ImportBatch;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class AdminDataManagementTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private Student $student;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(2, 1));
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['password_hash' => Hash::make('Synthetic administrator phrase'), 'must_change_password' => false]);
        $this->student = Student::findOrFail($this->rows['students']['id']);
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        $this->actingAs($this->admin);
    }

    private function confirmedSession(): array
    {
        return ['admin_password_confirmed_at' => now()->timestamp];
    }

    private function confirmAdminSession(): void
    {
        $this->withSession($this->confirmedSession());
    }

    private function studentImport(string $nisn = '0000000421', string $classCode = 'X-1'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', "name,nisn,major_code,class_code\nMurid Import,$nisn,RPL,$classCode\n");
    }

    public function test_admin_can_create_student_and_preserve_leading_zero_identifier(): void
    {
        Livewire::test(Students::class)
            ->set('name', 'Murid Baru')
            ->set('nisn', '0000000421')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Murid Baru');

        $user = User::where('name', 'Murid Baru')->firstOrFail();
        self::assertSame('0000000421', $user->student->nisn);
        self::assertSame('0000000421', $user->loginIdentifiers()->sole()->normalized_value);
        self::assertSame('pending_activation', $user->account_status);
        self::assertDatabaseHas('audit_logs', ['resource_id' => $user->id, 'action' => 'account_created']);
    }

    public function test_admin_can_create_teacher_without_optional_nip(): void
    {
        Livewire::test(Teachers::class)
            ->set('name', 'Guru Baru')
            ->set('teacherCode', 'GURU-02')
            ->set('nip', '')
            ->call('create')
            ->assertHasNoErrors();

        $teacher = Teacher::where('teacher_code', 'GURU-02')->firstOrFail();
        self::assertNull($teacher->nip);
        self::assertSame('pending_activation', $teacher->user->account_status);
    }

    public function test_student_filter_and_detail_keep_class_and_record_history(): void
    {
        Livewire::test(Students::class)
            ->set('search', '0012345678')
            ->assertSee('Synthetic Student')
            ->set('search', 'missing-name')
            ->assertSee('Tidak ada siswa yang cocok');

        Livewire::test(StudentDetail::class, ['studentId' => $this->student->id])
            ->assertSee('Synthetic Student')
            ->assertSee('Synthetic Class')
            ->assertSee('Synthetic Achievement')
            ->assertSee('Riwayat kelas');
    }

    public function test_student_search_alternatives_respect_the_account_status_filter(): void
    {
        foreach (['Synthetic Student', '0012345678'] as $term) {
            Livewire::test(Students::class)
                ->set('status', 'inactive')
                ->set('search', $term)
                ->assertSee('Tidak ada siswa yang cocok')
                ->assertDontSee('Synthetic Student');
        }
    }

    public function test_teacher_search_alternatives_respect_the_account_status_filter(): void
    {
        foreach (['Synthetic Teacher', 'G001', '00098765'] as $term) {
            Livewire::test(Teachers::class)
                ->set('status', 'inactive')
                ->set('search', $term)
                ->assertSee('Tidak ada guru yang cocok')
                ->assertDontSee('Synthetic Teacher');
        }
    }

    public function test_class_movement_keeps_existing_point_record_enrollment(): void
    {
        $destination = Classroom::create([
            'academic_year_id' => $this->semester->academic_year_id,
            'major_id' => $this->rows['classrooms']['major_id'],
            'code' => 'X-2', 'name' => 'Kelas Tujuan', 'grade' => 10,
        ]);

        $this->confirmAdminSession();
        Livewire::test(StudentDetail::class, ['studentId' => $this->student->id])
            ->set('destinationClassroomId', $destination->id)
            ->set('effectiveAt', '2026-08-01T02:02:00Z')
            ->call('move')
            ->assertHasNoErrors();

        self::assertDatabaseHas('point_records', ['id' => $this->rows['point_records']['id'], 'enrollment_id' => $this->rows['enrollments']['id']]);
        self::assertDatabaseHas('enrollments', ['student_id' => $this->student->id, 'classroom_id' => $destination->id]);
    }

    public function test_graduation_disables_account_and_preserves_student_history(): void
    {
        $this->confirmAdminSession();
        Livewire::test(StudentDetail::class, ['studentId' => $this->student->id])
            ->set('statusReason', 'Lulus setelah tahun ajaran selesai')
            ->call('graduate')
            ->assertHasNoErrors();

        self::assertSame('graduated', $this->student->fresh()->education_status);
        self::assertSame('inactive', $this->student->user->fresh()->account_status);
        self::assertDatabaseHas('point_records', ['id' => $this->rows['point_records']['id']]);
    }

    public function test_teacher_temporary_credential_reveal_rejects_expired_and_requires_current_confirmation(): void
    {
        $teacher = Teacher::where('teacher_code', 'G001')->firstOrFail();
        $teacher->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $temporary = TemporaryCredential::create(['user_id' => $teacher->user_id, 'encrypted_secret' => 'Synthetic teacher temporary secret', 'expires_at' => now()->addDay(), 'generated_by' => $this->admin->id, 'reason' => 'Synthetic initial credential']);
        $temporary->update(['expires_at' => now()->subSecond()]);

        $this->confirmAdminSession();
        Livewire::test(Teachers::class)
            ->set('credentialTeacherId', $teacher->id)
            ->call('revealTemporaryCredential')
            ->assertHasErrors('credential');

        $temporary->update(['expires_at' => now()->addDay()]);
        $this->withSession(['admin_password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        Livewire::test(Teachers::class)
            ->set('credentialTeacherId', $teacher->id)
            ->call('revealTemporaryCredential')
            ->assertHasErrors('reauthentication');

        self::assertDatabaseMissing('audit_logs', ['resource_id' => $teacher->user_id, 'action' => 'teacher_temporary_credential_revealed']);
    }

    public function test_teacher_temporary_credential_reveal_is_admin_only_and_audit_omits_secret(): void
    {
        $teacher = Teacher::where('teacher_code', 'G001')->firstOrFail();
        $teacher->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $secret = 'Synthetic teacher reveal secret';
        TemporaryCredential::create(['user_id' => $teacher->user_id, 'encrypted_secret' => $secret, 'expires_at' => now()->addDay(), 'generated_by' => $this->admin->id, 'reason' => 'Synthetic initial credential']);
        $this->confirmAdminSession();

        Livewire::test(Teachers::class)
            ->set('credentialTeacherId', $teacher->id)
            ->call('revealTemporaryCredential')
            ->assertHasNoErrors()
            ->assertSee($secret);

        $audit = AuditLog::where('resource_id', $teacher->user_id)->where('action', 'teacher_temporary_credential_revealed')->sole();
        self::assertStringNotContainsString($secret, json_encode($audit->safe_after));
    }

    public function test_academic_page_enrolls_before_open_and_audits_open_semester(): void
    {
        $next = Semester::create(['academic_year_id' => $this->semester->academic_year_id, 'name' => 'Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'draft']);
        $this->confirmAdminSession();
        Livewire::test(Academic::class)
            ->set('studentId', $this->student->id)
            ->set('semesterId', $next->id)
            ->set('classroomId', $this->rows['classrooms']['id'])
            ->set('effectiveAt', '2027-01-01')
            ->call('enroll')
            ->assertHasNoErrors();

        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(2, 0));
        $this->confirmAdminSession();
        Livewire::test(Academic::class)
            ->call('openSemester', $next->id)
            ->assertHasNoErrors();

        self::assertSame('active', $next->fresh()->status);
        self::assertDatabaseHas('audit_logs', ['resource_id' => $next->id, 'action' => 'semester_opened']);
    }

    public function test_csv_and_xlsx_templates_are_admin_downloads(): void
    {
        $this->get(route('admin.import.template', ['entityType' => 'student', 'format' => 'csv']))
            ->assertOk()
            ->assertDownload('template-student-civora.csv');
        $this->get(route('admin.import.template', ['entityType' => 'teacher', 'format' => 'xlsx']))
            ->assertOk()
            ->assertDownload('template-teacher-civora.xlsx');
    }

    public function test_teacher_import_needs_no_semester_and_creates_the_account(): void
    {
        $this->confirmAdminSession();
        Livewire::test(AccountsImport::class)
            ->set('entityType', 'teacher')
            ->set('file', UploadedFile::fake()->createWithContent('teachers.csv', "name,nip\nGuru Import,1234567890\n"))
            ->call('preview')
            ->assertHasNoErrors();

        $batch = ImportBatch::where('admin_id', $this->admin->id)->whereNotNull('created_at')->latest('created_at')->firstOrFail();
        self::assertNull($batch->semester_id);
        self::assertSame([], $batch->error_summary ?? []);
        Livewire::test(AccountsImport::class)
            ->set('batchId', $batch->id)
            ->call('confirm')
            ->assertHasNoErrors();

        self::assertSame('confirmed', $batch->fresh()->status);
        self::assertSame('pending_activation', User::whereHas('teacher', fn ($query) => $query->where('nip', '1234567890'))->sole()->account_status);
    }

    public function test_invalid_import_pins_row_errors_and_creates_no_users(): void
    {
        $this->confirmAdminSession();
        $before = User::count();
        Livewire::test(AccountsImport::class)
            ->set('entityType', 'student')
            ->set('semesterId', $this->semester->id)
            ->set('file', UploadedFile::fake()->createWithContent('students.csv', "name,nisn,major_code,class_code\nSensitivePassword,12,RPL,UNKNOWN\n"))
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Baris 2')
            ->assertSee('Kode kelas/jurusan harus cocok')
            ->assertDontSee('SensitivePassword');

        $batch = ImportBatch::where('admin_id', $this->admin->id)->whereNotNull('created_at')->latest('created_at')->firstOrFail();
        Livewire::test(AccountsImport::class)
            ->set('batchId', $batch->id)
            ->call('confirm')
            ->assertHasErrors('batch');

        self::assertSame($before, User::count());
        self::assertNotEmpty($batch->fresh()->error_summary);
    }

    public function test_valid_batch_commit_preserves_leading_zero_nisn_and_is_admin_owned(): void
    {
        $otherAdmin = User::create(['role' => 'admin', 'name' => 'Admin Kedua', 'password_hash' => Hash::make('Another administrator phrase'), 'account_status' => 'active', 'must_change_password' => false]);
        $otherAdmin->loginIdentifiers()->create(['role' => 'admin', 'kind' => 'username', 'normalized_value' => 'admin-kedua']);

        $this->confirmAdminSession();
        Livewire::test(AccountsImport::class)
            ->set('entityType', 'student')
            ->set('semesterId', $this->semester->id)
            ->set('file', $this->studentImport())
            ->call('preview')
            ->assertHasNoErrors();

        $batch = ImportBatch::where('admin_id', $this->admin->id)->whereNotNull('created_at')->latest('created_at')->firstOrFail();
        self::assertSame([], $batch->error_summary ?? []);
        Livewire::test(AccountsImport::class)
            ->set('batchId', $batch->id)
            ->call('confirm')
            ->assertHasNoErrors();

        self::assertSame('0000000421', Student::where('nisn', '0000000421')->sole()->nisn);
        self::assertSame('confirmed', $batch->fresh()->status);
        self::assertSame('pending_activation', User::whereHas('student', fn ($query) => $query->where('nisn', '0000000421'))->sole()->account_status);
        self::assertSame(1, Enrollment::where('student_id', Student::where('nisn', '0000000421')->value('id'))->count());

        $this->actingAs($otherAdmin, 'web');
        Livewire::test(AccountsImport::class)
            ->set('batchId', $batch->id)
            ->call('confirm')
            ->assertForbidden();
    }

    public function test_deactivated_class_after_preview_blocks_commit_without_creating_accounts(): void
    {
        $this->confirmAdminSession();
        Livewire::test(AccountsImport::class)
            ->set('entityType', 'student')
            ->set('semesterId', $this->semester->id)
            ->set('file', $this->studentImport())
            ->call('preview')
            ->assertHasNoErrors();
        $batch = ImportBatch::where('admin_id', $this->admin->id)->whereNotNull('created_at')->latest('created_at')->firstOrFail();
        Classroom::where('code', 'X-1')->update(['active' => false]);
        $before = User::count();

        Livewire::test(AccountsImport::class)
            ->set('batchId', $batch->id)
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertSee('kelas/jurusan harus cocok');

        self::assertSame($before, User::count());
        self::assertSame('failed', $batch->fresh()->status);
        self::assertSame(2, $batch->fresh()->error_summary[0]['row']);
    }

    public function test_admin_actions_require_fresh_password_confirmation_on_livewire_request(): void
    {
        Livewire::test(Students::class)
            ->set('name', 'Murid Tanpa Konfirmasi')
            ->set('nisn', '0000000765')
            ->call('create')
            ->assertHasNoErrors();

        Livewire::test(Students::class)
            ->set('targetUserId', User::where('name', 'Murid Tanpa Konfirmasi')->value('id'))
            ->set('reason', 'Perbaikan password yang diminta wali')
            ->call('resetPassword')
            ->assertHasErrors('reauthentication');
    }
}

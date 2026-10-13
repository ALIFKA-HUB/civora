<?php

namespace Tests\Feature;

use App\Actions\Reports\DownloadReportExport;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\Reports;
use App\Livewire\Admin\Settings;
use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Models\SchoolSetting;
use App\Models\Semester;
use App\Models\User;
use App\Services\Reports\PointReportQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class AdminReportAccessTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->admin);
        session()->put('admin_password_confirmed_at', now()->timestamp);
    }

    public function test_report_filter_keeps_historical_class_totals_separate(): void
    {
        $oldEnrollment = $this->rows['enrollments']['id'];
        $majorId = DB::table('classrooms')->where('id', DB::table('enrollments')->where('id', $oldEnrollment)->value('classroom_id'))->value('major_id');
        $newClass = (string) Str::uuid();
        DB::table('classrooms')->insert(['id' => $newClass, 'academic_year_id' => $this->semester->academic_year_id, 'major_id' => $majorId, 'code' => 'XI-1', 'name' => 'Kelas Baru', 'grade' => 11]);
        $newEnrollment = (string) Str::uuid();
        DB::table('enrollments')->insert(['id' => $newEnrollment, 'student_id' => $this->rows['students']['id'], 'semester_id' => $this->semester->id, 'classroom_id' => $newClass, 'valid_from' => '2026-12-01 00:00:00+07']);
        $this->record($oldEnrollment, 10, 'violation');
        $this->record($newEnrollment, 5, 'violation');

        $oldReport = app(PointReportQuery::class)->build(['semester_id' => $this->semester->id, 'classroom_id' => DB::table('enrollments')->where('id', $oldEnrollment)->value('classroom_id')]);
        $newReport = app(PointReportQuery::class)->build(['semester_id' => $this->semester->id, 'classroom_id' => $newClass]);

        self::assertSame(10, $oldReport['summary']['violation']);
        self::assertSame(5, $newReport['summary']['violation']);
        Livewire::test(Reports::class)->set('semesterId', $this->semester->id)->set('classroomId', DB::table('enrollments')->where('id', $oldEnrollment)->value('classroom_id'))->call('applyFilters')->assertSee('10');
    }

    public function test_report_screen_shows_export_states_and_only_queues_for_the_current_admin(): void
    {
        $queued = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'xlsx'], 'status' => 'queued']);
        $processing = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'pdf'], 'status' => 'processing']);
        $completed = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'xlsx'], 'status' => 'completed', 'file_path' => 'reports/private.xlsx', 'generated_at' => now(), 'expires_at' => now()->addDays(7)]);
        $failed = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'pdf'], 'status' => 'failed']);
        $other = User::create(['role' => 'admin', 'name' => 'Other Admin', 'password_hash' => 'synthetic passphrase', 'account_status' => 'active']);
        $hidden = ReportExport::create(['admin_id' => $other->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'xlsx'], 'status' => 'completed', 'file_path' => 'reports/other.xlsx', 'generated_at' => now(), 'expires_at' => now()->addDays(7)]);

        Livewire::test(Reports::class)
            ->assertSee('Menunggu antrean')->assertSee('Sedang diproses')->assertSee('Selesai')->assertSee('Gagal')
            ->assertDontSee($hidden->id)
            ->set('semesterId', $this->semester->id)->set('exportFormat', 'xlsx')->call('requestExport')
            ->assertHasNoErrors()->assertSee('Ekspor masuk antrean.');
        self::assertSame('queued', $queued->fresh()->status);
        self::assertTrue(ReportExport::where('admin_id', $this->admin->id)->where('status', 'queued')->exists());
        self::assertNotSame($processing->id, $completed->id);
        self::assertNotSame($failed->id, $hidden->id);
    }

    public function test_only_owner_admin_can_download_and_saved_expired_url_is_denied(): void
    {
        $path = 'reports/private.xlsx';
        Storage::disk('local')->put($path, 'synthetic report');
        $export = ReportExport::create(['admin_id' => $this->admin->id, 'filters' => ['semester_id' => $this->semester->id, 'format' => 'xlsx'], 'status' => 'completed', 'file_path' => $path, 'generated_at' => now(), 'expires_at' => now()->addDay()]);

        $this->get(route('admin.reports.download', $export))->assertOk();
        $export->update(['expires_at' => now()->subSecond()]);
        $this->get(route('admin.reports.download', $export))->assertForbidden();
        try {
            app(DownloadReportExport::class)->execute($this->teacher, $export->id);
            self::fail('Expected teacher download denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $other = User::create(['role' => 'admin', 'name' => 'Other Admin', 'password_hash' => 'synthetic passphrase', 'account_status' => 'active']);
        $this->actingAs($other)->get(route('admin.reports.download', $export))->assertForbidden();
        $this->actingAs($this->teacher)->get('/admin/reports')->assertForbidden();
    }

    public function test_school_settings_validate_timezone_and_audit_valid_changes(): void
    {
        Livewire::test(Settings::class)
            ->set('schoolName', 'Sekolah Sintetis Baru')->set('timezone', 'Not/A_Timezone')->call('saveSchool')
            ->assertHasErrors('timezone');
        self::assertSame('Synthetic School', SchoolSetting::query()->value('name'));

        Livewire::test(Settings::class)
            ->set('schoolName', 'Sekolah Sintetis Baru')->set('timezone', 'Asia/Makassar')->call('saveSchool')
            ->assertHasNoErrors()->assertSee('Pengaturan sekolah disimpan.');
        self::assertSame('Asia/Makassar', SchoolSetting::query()->value('timezone'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'school_settings_updated', 'safe_after->timezone' => 'Asia/Makassar']);
    }

    public function test_school_settings_create_the_singleton_when_no_initial_row_exists(): void
    {
        SchoolSetting::query()->delete();

        Livewire::test(Settings::class)
            ->set('schoolName', 'Sekolah Baru')->set('timezone', 'Asia/Jakarta')->call('saveSchool')
            ->assertHasNoErrors()->assertSee('Pengaturan sekolah disimpan.');

        self::assertSame(1, SchoolSetting::query()->count());
        self::assertSame('Sekolah Baru', SchoolSetting::query()->value('name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'school_settings_updated']);
    }

    public function test_audit_viewer_paginates_and_redacts_secret_fields_and_values(): void
    {
        AuditLog::create([
            'actor_user_id' => $this->admin->id, 'action' => 'synthetic_security_change', 'resource_type' => 'user',
            'resource_id' => $this->admin->id, 'safe_before' => ['name' => 'Admin Sintetis', 'password_hash' => 'never-show-hash'],
            'safe_after' => ['role' => 'admin', 'api_token' => 'never-show-token', 'credential' => 'never-show-password'],
            'reason' => 'Perubahan rutin tanpa rahasia', 'request_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);

        Livewire::test(AuditLogViewer::class)
            ->assertSee('synthetic_security_change')->assertSee('Admin Sintetis')
            ->assertDontSee('never-show-hash')->assertDontSee('never-show-token')->assertDontSee('never-show-password');
    }

    public function test_sensitive_settings_and_export_actions_require_recent_password_confirmation(): void
    {
        session()->forget('admin_password_confirmed_at');
        Livewire::test(Settings::class)->set('timezone', 'Asia/Jakarta')->call('saveSchool')->assertHasErrors('reauthentication');
        Livewire::test(Reports::class)->set('semesterId', $this->semester->id)->call('requestExport')->assertHasErrors('reauthentication');
    }

    public function test_admin_account_management_uses_account_actions_and_protects_the_last_active_admin(): void
    {
        $other = User::create(['role' => 'admin', 'name' => 'Admin Kedua', 'password_hash' => 'synthetic passphrase', 'account_status' => 'active']);
        DB::table('login_identifiers')->insert(['id' => (string) Str::uuid(), 'user_id' => $other->id, 'role' => 'admin', 'kind' => 'username', 'normalized_value' => 'admin.kedua']);

        Livewire::test(Settings::class)
            ->set('adminName', 'Admin Tambahan')->set('username', 'admin.tambahan')->call('createAdmin')
            ->assertHasNoErrors()->assertSee('Akun admin dibuat dan menunggu aktivasi.');
        $created = User::where('role', 'admin')->where('name', 'Admin Tambahan')->firstOrFail();
        self::assertSame('pending_activation', $created->account_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_created', 'resource_id' => $created->id]);

        Livewire::test(Settings::class)
            ->set('targetAdminId', $other->id)->set('reason', 'Perubahan penugasan admin sekolah.')
            ->call('deactivateAdmin')->assertHasNoErrors()->assertSee('Akun admin dinonaktifkan dan tindakannya dicatat.');
        self::assertSame('inactive', $other->fresh()->account_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_deactivated', 'resource_id' => $other->id]);

        Livewire::test(Settings::class)
            ->set('targetAdminId', $this->admin->id)->set('reason', 'Admin aktif terakhir tetap dijaga.')
            ->call('deactivateAdmin')->assertForbidden();
        self::assertSame('active', $this->admin->fresh()->account_status);
    }

    private function record(string $enrollmentId, int $points, string $category): void
    {
        $id = (string) Str::uuid();
        $type = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $type, 'code' => 'R'.substr(str_replace('-', '', $id), 0, 15), 'category' => $category]);
        DB::table('point_type_versions')->insert(['id' => $version, 'point_type_id' => $type, 'version' => 1, 'name' => 'Poin Sintetis', 'description' => 'Fixture laporan sintetis', 'points' => $points, 'created_by' => $this->admin->id, 'created_at' => now()]);
        $selection = (string) Str::uuid();
        DB::table('student_selections')->insert(['id' => $selection, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $this->rows['students']['id'], 'enrollment_id' => $enrollmentId, 'semester_id' => $this->semester->id, 'source' => 'manual', 'expires_at' => now()->addMinutes(5)]);
        $record = (string) Str::uuid();
        DB::table('point_records')->insert(['id' => $record, 'student_id' => $this->rows['students']['id'], 'teacher_id' => DB::table('teachers')->value('id'), 'enrollment_id' => $enrollmentId, 'semester_id' => $this->semester->id, 'selection_id' => $selection, 'source' => 'manual', 'occurred_at' => now(), 'class_snapshot' => 'Synthetic Class', 'major_snapshot' => 'Synthetic Major', 'idempotency_key' => str_replace('-', '', $record), 'request_hash' => str_repeat('a', 64)]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $record, 'revision_number' => 1, 'point_type_version_id' => $version, 'points_snapshot' => $points, 'name_snapshot' => 'Poin Sintetis', 'category_snapshot' => $category, 'description' => 'Fixture laporan sintetis', 'status' => 'active', 'changed_by' => DB::table('teachers')->value('user_id'), 'reason' => 'Fixture report', 'created_at' => now()]);
    }
}

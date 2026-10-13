<?php

namespace Tests\Feature;

use App\Actions\Points\SetThresholdPolicy;
use App\Livewire\Admin\FollowUps;
use App\Livewire\Admin\PointTypes;
use App\Livewire\Admin\Thresholds;
use App\Models\FollowUp;
use App\Models\PointType;
use App\Models\PointTypeVersion;
use App\Models\Semester;
use App\Models\Student;
use App\Models\ThresholdAlert;
use App\Models\ThresholdPolicy;
use App\Models\User;
use App\Services\Points\PointTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class AdminPointRulesTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private Student $student;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['account_status' => 'active', 'must_change_password' => false, 'password_hash' => Hash::make('Synthetic administrator phrase')]);
        $this->student = Student::findOrFail($this->rows['students']['id']);
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        $this->actingAs($this->admin)->withSession(['admin_password_confirmed_at' => now()->timestamp]);
    }

    public function test_point_type_screen_uses_catalog_actions_and_preserves_historic_values_when_revised_and_deactivated(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);
        $oldVersion = PointTypeVersion::findOrFail($this->rows['point_type_versions']['id']);
        $recordSnapshot = DB::table('record_revisions')->where('id', $this->rows['record_revisions']['id'])->first();

        Livewire::test(PointTypes::class)
            ->set('code', 'A002')
            ->set('category', 'achievement')
            ->set('name', 'Penghargaan Baru')
            ->set('points', 0)
            ->set('description', 'Deskripsi katalog baru')
            ->call('create')
            ->assertHasErrors(['points'])
            ->set('points', 15)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Versi 1');

        $newType = PointType::where('code', 'A002')->firstOrFail();
        $newVersion = $newType->pointTypeVersions()->firstOrFail();
        self::assertSame(15, $newVersion->points);

        Livewire::test(PointTypes::class)
            ->call('beginRevision', $type->id)
            ->set('name', 'Prestasi Direvisi')
            ->set('points', 20)
            ->set('description', 'Nilai revisi berlaku untuk catatan baru')
            ->call('revise')
            ->assertHasNoErrors()
            ->assertSee('Versi 2');

        self::assertSame(10, $oldVersion->fresh()->points);
        self::assertSame(10, $recordSnapshot->points_snapshot);
        self::assertSame(20, $type->pointTypeVersions()->orderByDesc('version')->firstOrFail()->points);

        Livewire::test(PointTypes::class)
            ->call('deactivate', $type->id)
            ->assertHasNoErrors()
            ->assertSee('Nonaktif')
            ->assertSee('Prestasi Direvisi');

        self::assertFalse($type->fresh()->active);
        self::assertSame(2, $type->pointTypeVersions()->count());
    }

    public function test_threshold_screen_rejects_duplicate_levels_and_appends_policy_version(): void
    {
        $oldPolicy = ThresholdPolicy::findOrFail($this->rows['threshold_policies']['id']);
        $oldLevel = $oldPolicy->thresholdLevels()->firstOrFail();

        Livewire::test(Thresholds::class)
            ->set('semesterId', $this->semester->id)
            ->set('reason', 'Kebijakan baru semester')
            ->set('levels', [
                ['boundary_points' => 5, 'label' => 'Tahap awal', 'recommended_action' => 'Hubungi wali kelas'],
                ['boundary_points' => 5, 'label' => 'Tahap ganda', 'recommended_action' => 'Periksa kembali'],
            ])
            ->call('save')
            ->assertHasErrors(['levels.1.boundary_points']);

        self::assertSame(1, ThresholdPolicy::where('semester_id', $this->semester->id)->count());

        Livewire::test(Thresholds::class)
            ->set('semesterId', $this->semester->id)
            ->set('reason', 'Kebijakan baru semester')
            ->set('levels', [
                ['boundary_points' => 5, 'label' => 'Tahap awal', 'recommended_action' => 'Hubungi wali kelas'],
                ['boundary_points' => 12, 'label' => 'Tahap lanjut', 'recommended_action' => 'Jadwalkan pembinaan'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Kebijakan versi 2');

        $newPolicy = $this->semester->thresholdPolicies()->orderByDesc('version')->firstOrFail();
        self::assertSame(2, $newPolicy->version);
        self::assertSame(25, $oldLevel->fresh()->boundary_points);
        self::assertSame(2, ThresholdPolicy::where('semester_id', $this->semester->id)->count());
        Livewire::test(Thresholds::class)
            ->set('semesterId', $this->semester->id)
            ->assertSee('Kebijakan versi 2')
            ->assertSee('Peringatan dari kebijakan terbaru');
    }

    public function test_follow_up_screen_records_status_without_changing_point_totals(): void
    {
        $alert = ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']);
        $before = app(PointTotals::class)->forStudent($this->student, $this->semester);

        Livewire::test(FollowUps::class)
            ->set('alertId', $alert->id)
            ->set('action', 'Pertemuan dengan wali')
            ->set('scheduledOrPerformedAt', '2026-08-03T10:00')
            ->assertSet('scheduledOrPerformedAt', '2026-08-03T10:00')
            ->set('notes', 'Catatan tindak lanjut sintetis')
            ->set('status', 'completed')
            ->call('record')
            ->assertHasNoErrors()
            ->assertSee('Selesai');

        self::assertSame($before, app(PointTotals::class)->forStudent($this->student, $this->semester));
        $this->assertDatabaseHas('follow_ups', [
            'threshold_alert_id' => $alert->id,
            'performed_by' => $this->admin->id,
            'status' => 'completed',
            'action' => 'Pertemuan dengan wali',
        ]);

        $followUp = FollowUp::where('threshold_alert_id', $alert->id)->where('action', 'Pertemuan dengan wali')->firstOrFail();
        self::assertSame('2026-08-03T03:00:00+00:00', $followUp->scheduled_or_performed_at->utc()->format('Y-m-d\TH:i:sP'));
        Livewire::test(FollowUps::class)
            ->set('semesterId', $this->semester->id)
            ->assertSee($followUp->scheduled_or_performed_at->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i'));
    }

    public function test_follow_up_handler_rejects_a_forged_inactive_alert_id(): void
    {
        $alert = ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']);
        $alert->update(['currently_reached' => false]);

        Livewire::test(FollowUps::class)
            ->set('semesterId', $this->semester->id)
            ->set('alertId', $alert->id)
            ->set('action', 'Pertemuan dengan wali')
            ->set('scheduledOrPerformedAt', '2026-08-03T10:00')
            ->set('status', 'planned')
            ->call('record')
            ->assertHasErrors(['alertId']);

        self::assertSame(0, DB::table('follow_ups')->where('threshold_alert_id', $alert->id)->where('action', 'Pertemuan dengan wali')->count());
    }

    public function test_follow_up_handler_rejects_an_old_policy_alert_even_if_its_row_is_still_active(): void
    {
        $alert = ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']);
        app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, [[
            'boundary_points' => 20,
            'label' => 'Tingkat baru',
            'recommended_action' => 'Periksa kondisi terbaru',
        ]], 'Ganti kebijakan ambang');
        $alert->update(['currently_reached' => true]);

        Livewire::test(FollowUps::class)
            ->set('semesterId', $this->semester->id)
            ->set('alertId', $alert->id)
            ->set('action', 'Pertemuan dengan wali')
            ->set('scheduledOrPerformedAt', '2026-08-03T10:00')
            ->set('status', 'planned')
            ->call('record')
            ->assertHasErrors(['alertId']);

        self::assertSame(0, DB::table('follow_ups')->where('threshold_alert_id', $alert->id)->where('action', 'Pertemuan dengan wali')->count());
    }

    public function test_mutating_catalog_requires_recent_admin_confirmation_at_livewire_handler(): void
    {
        $this->withSession(['admin_password_confirmed_at' => now()->subMinutes(16)->timestamp]);

        Livewire::test(PointTypes::class)
            ->set('code', 'A002')
            ->set('category', 'achievement')
            ->set('name', 'Penghargaan Baru')
            ->set('points', 15)
            ->set('description', 'Deskripsi katalog baru')
            ->call('create')
            ->assertHasErrors(['reauthentication']);

        self::assertFalse(PointType::where('code', 'A002')->exists());
    }
}

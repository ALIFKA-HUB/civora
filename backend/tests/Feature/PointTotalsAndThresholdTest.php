<?php

namespace Tests\Feature;

use App\Actions\Points\RecordFollowUp;
use App\Actions\Points\SetThresholdPolicy;
use App\Models\Semester;
use App\Models\Student;
use App\Models\ThresholdAlert;
use App\Models\ThresholdPolicy;
use App\Models\User;
use App\Services\Points\PointTotals;
use App\Services\Points\ThresholdEvaluator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class PointTotalsAndThresholdTest extends TestCase
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
    }

    public function test_totals_use_latest_active_revision_and_only_the_selected_semester(): void
    {
        $violation = $this->record('violation', 15, $this->semester);
        $this->revise($violation, 2, 5, 'active');
        $cancelled = $this->record('achievement', 7, $this->semester);
        $this->revise($cancelled, 2, 7, 'cancelled');
        $otherSemester = $this->otherSemester();
        $this->record('violation', 100, $otherSemester);

        $totals = app(PointTotals::class)->forStudent($this->student, $this->semester);

        self::assertSame(['achievement' => 10, 'violation' => 5], $totals);
    }

    public function test_threshold_evaluator_marks_exact_and_all_crossed_levels_without_duplicates(): void
    {
        $record = $this->record('violation', 10, $this->semester);
        app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, $this->levels(5, 10), 'Threshold setup reason');

        $this->evaluate();

        $alerts = ThresholdAlert::where('student_id', $this->student->id)->where('semester_id', $this->semester->id)->where('currently_reached', true)->get();
        self::assertCount(2, $alerts);
        self::assertSame([5, 10], $alerts->map(fn (ThresholdAlert $alert) => $alert->thresholdLevel->boundary_points)->sort()->values()->all());

        $this->revise($record, 2, 4, 'active');
        $this->evaluate();
        self::assertSame(0, ThresholdAlert::where('semester_id', $this->semester->id)->where('currently_reached', true)->count());

        $this->revise($record, 3, 10, 'active');
        $this->evaluate();
        $this->evaluate();

        self::assertSame(3, ThresholdAlert::where('semester_id', $this->semester->id)->count());
        self::assertSame(2, ThresholdAlert::where('semester_id', $this->semester->id)->where('currently_reached', true)->count());
    }

    public function test_new_policy_replaces_current_status_and_keeps_old_alert_follow_up(): void
    {
        $this->record('violation', 10, $this->semester);
        $oldAlert = ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']);
        $oldFollowUpId = $this->rows['follow_ups']['id'];

        $policy = app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, $this->levels(10), 'Replace policy reason');

        self::assertSame(2, $policy->version);
        self::assertFalse($oldAlert->fresh()->currently_reached);
        self::assertDatabaseHas('follow_ups', ['id' => $oldFollowUpId, 'threshold_alert_id' => $oldAlert->id]);
        self::assertTrue(ThresholdAlert::where('semester_id', $this->semester->id)->where('threshold_level_id', $policy->thresholdLevels()->value('id'))->value('currently_reached'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'threshold_policy_created', 'resource_id' => $policy->id, 'reason' => 'Replace policy reason']);
    }

    public function test_closed_semester_policy_change_requires_a_valid_reason(): void
    {
        $this->semester->update(['status' => 'closed']);

        try {
            app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, $this->levels(10), 'bad');
            self::fail('Expected reason validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }
        self::assertSame(1, ThresholdPolicy::where('semester_id', $this->semester->id)->count());

        $policy = app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, $this->levels(10), 'Closed semester correction');
        self::assertSame(2, $policy->version);
    }

    public function test_policy_rejects_nonpositive_duplicate_boundaries_and_blank_labels_or_actions(): void
    {
        foreach ([
            $this->levels(0),
            $this->levels(5, 5),
            [['boundary_points' => 5, 'label' => '   ', 'recommended_action' => 'Valid action']],
            [['boundary_points' => 5, 'label' => 'Valid label', 'recommended_action' => '   ']],
        ] as $levels) {
            try {
                app(SetThresholdPolicy::class)->execute($this->admin, $this->semester, $levels, 'Threshold setup reason');
                self::fail('Expected threshold level validation failure.');
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
        self::assertSame(1, ThresholdPolicy::where('semester_id', $this->semester->id)->count());
    }

    public function test_only_active_admin_can_change_policy_or_record_follow_up(): void
    {
        $this->expectException(AuthorizationException::class);
        app(SetThresholdPolicy::class)->execute($this->teacher, $this->semester, $this->levels(10), 'Threshold setup reason');
    }

    public function test_stale_admin_cannot_change_policy_after_deactivation(): void
    {
        $admin = $this->admin;
        $admin->update(['account_status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        app(SetThresholdPolicy::class)->execute($admin, $this->semester, $this->levels(10), 'Threshold setup reason');
    }

    public function test_follow_up_validation_and_completion_do_not_change_point_totals(): void
    {
        $record = $this->record('violation', 12, $this->semester);
        $alert = ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']);
        $before = app(PointTotals::class)->forStudent($this->student, $this->semester);

        try {
            app(RecordFollowUp::class)->execute($this->admin, $alert, ['action' => 'Call family', 'scheduled_or_performed_at' => '2026-08-02 10:00:00+07', 'status' => 'invalid']);
            self::fail('Expected follow-up status validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->errors());
        }

        $followUp = app(RecordFollowUp::class)->execute($this->admin, $alert, [
            'action' => 'Call family', 'scheduled_or_performed_at' => '2026-08-02 10:00:00+07', 'notes' => 'Completed synthetic follow-up', 'status' => 'completed',
        ]);

        self::assertSame('completed', $followUp->status);
        self::assertSame($before, app(PointTotals::class)->forStudent($this->student, $this->semester));
        self::assertSame(12, DB::table('record_revisions')->where('point_record_id', $record->id)->value('points_snapshot'));
    }

    public function test_teacher_cannot_record_follow_up(): void
    {
        $this->expectException(AuthorizationException::class);
        app(RecordFollowUp::class)->execute(User::findOrFail(DB::table('teachers')->value('user_id')), ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']), [
            'action' => 'Call family', 'scheduled_or_performed_at' => '2026-08-02 10:00:00+07', 'status' => 'planned',
        ]);
    }

    public function test_inactive_admin_cannot_record_follow_up(): void
    {
        $this->admin->update(['account_status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        app(RecordFollowUp::class)->execute($this->admin, ThresholdAlert::findOrFail($this->rows['threshold_alerts']['id']), [
            'action' => 'Call family', 'scheduled_or_performed_at' => '2026-08-02 10:00:00+07', 'status' => 'planned',
        ]);
    }

    private function levels(int ...$boundaries): array
    {
        return array_map(fn (int $boundary) => ['boundary_points' => $boundary, 'label' => "Level $boundary", 'recommended_action' => "Action for $boundary"], $boundaries);
    }

    private function evaluate(): void
    {
        DB::transaction(function (): void {
            Semester::whereKey($this->semester->id)->lockForUpdate()->firstOrFail();
            Student::whereKey($this->student->id)->lockForUpdate()->firstOrFail();
            app(ThresholdEvaluator::class)->evaluate($this->student, $this->semester);
        });
    }

    private function record(string $category, int $points, Semester $semester, string $status = 'active'): object
    {
        $suffix = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $typeId = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $typeId, 'code' => substr(str_replace('-', '', $suffix), 0, 20), 'category' => $category, 'active' => true]);
        DB::table('point_type_versions')->insert(['id' => $versionId, 'point_type_id' => $typeId, 'version' => 1, 'name' => "Synthetic $category", 'description' => 'Synthetic total fixture', 'points' => $points, 'created_by' => $this->admin->id, 'created_at' => now()]);
        $selectionId = (string) Str::uuid();
        DB::table('student_selections')->insert(['id' => $selectionId, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $this->student->id, 'enrollment_id' => $this->rows['enrollments']['id'], 'semester_id' => $semester->id, 'source' => 'manual', 'expires_at' => now()->addMinutes(5)]);
        $recordId = (string) Str::uuid();
        DB::table('point_records')->insert(['id' => $recordId, 'student_id' => $this->student->id, 'teacher_id' => DB::table('teachers')->value('id'), 'enrollment_id' => $this->rows['enrollments']['id'], 'semester_id' => $semester->id, 'selection_id' => $selectionId, 'source' => 'manual', 'occurred_at' => now(), 'class_snapshot' => 'Synthetic Class', 'major_snapshot' => 'Synthetic Major', 'idempotency_key' => $suffix, 'request_hash' => str_repeat('d', 64)]);
        $revisionId = (string) Str::uuid();
        DB::table('record_revisions')->insert(['id' => $revisionId, 'point_record_id' => $recordId, 'revision_number' => 1, 'point_type_version_id' => $versionId, 'points_snapshot' => $points, 'name_snapshot' => "Synthetic $category", 'category_snapshot' => $category, 'description' => 'Synthetic total fixture', 'status' => $status, 'changed_by' => $this->teacher->id, 'reason' => 'Initial synthetic record', 'created_at' => now()]);

        return (object) ['id' => $recordId, 'version_id' => $versionId, 'category' => $category];
    }

    private function revise(object $record, int $revision, int $points, string $status): void
    {
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $record->id, 'revision_number' => $revision, 'point_type_version_id' => $record->version_id, 'points_snapshot' => $points, 'name_snapshot' => "Synthetic $record->category", 'category_snapshot' => $record->category, 'description' => 'Synthetic corrected record', 'status' => $status, 'changed_by' => $this->admin->id, 'reason' => 'Synthetic correction', 'created_at' => now()]);
    }

    private function otherSemester(): Semester
    {
        $yearId = (string) Str::uuid();
        DB::table('academic_years')->insert(['id' => $yearId, 'name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30']);
        $semesterId = (string) Str::uuid();
        DB::table('semesters')->insert(['id' => $semesterId, 'academic_year_id' => $yearId, 'name' => 'Ganjil', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'closed']);

        return Semester::findOrFail($semesterId);
    }
}

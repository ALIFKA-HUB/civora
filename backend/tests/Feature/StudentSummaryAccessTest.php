<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class StudentSummaryAccessTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->student = User::findOrFail(DB::table('students')->value('user_id'));
        $this->student->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacher->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    public function test_summary_returns_effective_totals_and_cannot_be_targeted_at_another_student(): void
    {
        $otherUser = User::create(['role' => 'student', 'name' => 'Other student', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        $otherStudentId = (string) Str::uuid();
        DB::table('students')->insert(['id' => $otherStudentId, 'user_id' => $otherUser->id, 'nisn' => '0088776655']);
        $otherSelectionId = (string) Str::uuid();
        DB::table('student_selections')->insert(['id' => $otherSelectionId, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $otherStudentId, 'enrollment_id' => $this->rows['enrollments']['id'], 'semester_id' => $this->rows['semesters']['id'], 'source' => 'manual', 'expires_at' => now()->addMinutes(5)]);
        $otherRecordId = (string) Str::uuid();
        DB::table('point_records')->insert(['id' => $otherRecordId, 'student_id' => $otherStudentId, 'teacher_id' => DB::table('teachers')->value('id'), 'enrollment_id' => $this->rows['enrollments']['id'], 'semester_id' => $this->rows['semesters']['id'], 'selection_id' => $otherSelectionId, 'source' => 'manual', 'occurred_at' => now(), 'class_snapshot' => 'Other Class', 'major_snapshot' => 'Other Major', 'idempotency_key' => 'other-summary-record', 'request_hash' => str_repeat('f', 64)]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $otherRecordId, 'revision_number' => 1, 'point_type_version_id' => $this->rows['point_type_versions']['id'], 'points_snapshot' => 999, 'name_snapshot' => 'Other record', 'category_snapshot' => 'achievement', 'description' => 'Other student synthetic record', 'status' => 'active', 'changed_by' => $this->teacher->id, 'reason' => 'Initial other record', 'created_at' => now()]);
        DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $this->rows['point_records']['id'], 'revision_number' => 2, 'point_type_version_id' => $this->rows['point_type_versions']['id'], 'points_snapshot' => 4, 'name_snapshot' => 'Synthetic Achievement', 'category_snapshot' => 'achievement', 'description' => 'Corrected summary record', 'status' => 'active', 'changed_by' => $this->rows['users']['id'], 'reason' => 'Admin verified corrected value', 'created_at' => now()]);

        $this->withToken($this->student->createToken('student')->plainTextToken)
            ->getJson('/api/v1/student/summary?student_id='.$otherStudentId)
            ->assertOk()
            ->assertJsonPath('data.totals.achievement', 4)
            ->assertJsonPath('data.totals.violation', 0)
            ->assertJsonStructure(['data' => ['semester', 'totals', 'latest_threshold_status']]);
    }

    public function test_summary_denies_nonstudent_and_pending_actors(): void
    {
        $this->withToken($this->teacher->createToken('teacher')->plainTextToken)->getJson('/api/v1/student/summary')->assertForbidden();
        $pending = User::findOrFail($this->student->id);
        $pending->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($pending->createToken('student-pending')->plainTextToken)->getJson('/api/v1/student/summary')->assertForbidden();
    }

    public function test_missing_active_semester_is_explicitly_unavailable_and_missing_placement_is_null(): void
    {
        $token = $this->student->createToken('student')->plainTextToken;
        DB::table('enrollments')->update(['valid_until' => '2026-08-01 00:00:00+07']);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.own_enrollment', null);

        DB::table('semesters')->where('status', 'active')->update(['status' => 'closed']);
        $this->app['auth']->forgetGuards();
        $summary = $this->withToken($this->student->createToken('student-summary')->plainTextToken)->getJson('/api/v1/student/summary');
        $summary->assertOk()->assertJsonPath('data.semester', null)->assertJsonPath('data.totals', null)->assertJsonPath('data.latest_threshold_status', null);
    }
}

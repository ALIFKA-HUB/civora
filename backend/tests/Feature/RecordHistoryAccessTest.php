<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class RecordHistoryAccessTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        DB::table('review_requests')->delete();
        $this->student = User::findOrFail(DB::table('students')->value('user_id'));
        $this->student->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacher->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    public function test_student_history_is_limited_to_their_own_records_and_objection_submission(): void
    {
        $other = $this->otherRecord();
        $this->withToken($this->student->createToken('student')->plainTextToken)
            ->getJson('/api/v1/student/records')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->rows['point_records']['id']])
            ->assertJsonMissing(['id' => $other]);

        $this->withToken($this->student->createToken('student')->plainTextToken)
            ->getJson('/api/v1/student/records/'.$other)
            ->assertNotFound();

        $objection = $this->withToken($this->student->createToken('student')->plainTextToken)
            ->postJson('/api/v1/student/records/'.$this->rows['point_records']['id'].'/objections', ['reason' => 'Mohon periksa kembali catatan ini.']);
        self::assertSame(201, $objection->getStatusCode(), $objection->getContent());
        $objection->assertJsonPath('data.status', 'pending');
    }

    public function test_teacher_history_includes_owned_archive_and_student_history_requires_active_student(): void
    {
        $this->student->student->update(['education_status' => 'graduated']);
        $token = $this->teacher->createToken('teacher')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/teacher/records/'.$this->rows['point_records']['id'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/teacher/students/'.$this->student->student->id.'/records')->assertNotFound();
        $correction = $this->withToken($token)->postJson('/api/v1/teacher/records/'.$this->rows['point_records']['id'].'/correction-requests', [
            'reason' => 'Mohon koreksi catatan yang saya buat.',
            'proposed_change' => ['operation' => 'cancel'],
        ]);
        self::assertSame(201, $correction->getStatusCode(), $correction->getContent());
        $correction->assertJsonPath('data.kind', 'teacher_correction');
    }

    public function test_pending_actors_cannot_read_history_or_summary(): void
    {
        $this->student->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $token = $this->student->createToken('student')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/student/records')->assertForbidden();
    }

    public function test_teacher_student_search_exposes_active_current_year_filter_options_when_results_are_empty(): void
    {
        $currentYear = $this->rows['academic_years']['id'];
        DB::table('classrooms')->insert([
            'id' => (string) Str::uuid(), 'academic_year_id' => $currentYear, 'major_id' => $this->rows['majors']['id'],
            'code' => 'X-2', 'name' => 'Second active class', 'grade' => 10, 'active' => true,
        ]);
        $oldYearId = (string) Str::uuid();
        $oldMajorId = (string) Str::uuid();
        DB::table('academic_years')->insert(['id' => $oldYearId, 'name' => '2025/2026', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30']);
        DB::table('majors')->insert(['id' => $oldMajorId, 'code' => 'OLD', 'name' => 'Old major', 'active' => true]);
        $oldClassroomId = (string) Str::uuid();
        DB::table('classrooms')->insert([
            'id' => $oldClassroomId, 'academic_year_id' => $oldYearId, 'major_id' => $oldMajorId,
            'code' => 'IX-1', 'name' => 'Prior year class', 'grade' => 9, 'active' => true,
        ]);
        $otherUserId = (string) Str::uuid();
        $otherStudentId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $otherUserId, 'role' => 'student', 'name' => 'Misplaced student', 'password_hash' => 'synthetic-hash', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('students')->insert(['id' => $otherStudentId, 'user_id' => $otherUserId, 'nisn' => '0088776655']);
        DB::table('enrollments')->insert(['id' => (string) Str::uuid(), 'student_id' => $otherStudentId, 'semester_id' => $this->rows['semesters']['id'], 'classroom_id' => $oldClassroomId, 'valid_from' => '2026-07-01 00:00:00+07']);

        $response = $this->withToken($this->teacher->createToken('teacher')->plainTextToken)
            ->getJson('/api/v1/teacher/students?q=no-matching-student');
        $response->assertOk()->assertJsonPath('data', []);
        self::assertSame(['Second active class', 'Synthetic Class'], collect($response->json('meta.filters.classrooms'))->pluck('name')->sort()->values()->all());
        self::assertSame(['Synthetic Major'], collect($response->json('meta.filters.majors'))->pluck('name')->values()->all());
        $this->withToken($this->teacher->createToken('teacher-filter')->plainTextToken)
            ->getJson('/api/v1/teacher/students?classroom_id='.$oldClassroomId)
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_me_exposes_current_profile_placement_and_minimal_pending_identity(): void
    {
        $me = $this->withToken($this->student->createToken('student')->plainTextToken)->getJson('/api/v1/me');
        self::assertSame(200, $me->getStatusCode(), $me->getContent());
        $me->assertJsonPath('data.current_semester.id', $this->rows['semesters']['id'])
            ->assertJsonPath('data.own_enrollment.major.code', 'RPL');

        $pendingUser = User::findOrFail($this->student->id);
        $pendingUser->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $this->app['auth']->forgetGuards();
        $pending = $this->withToken($pendingUser->createToken('student-pending')->plainTextToken)->getJson('/api/v1/me');
        self::assertSame([
            'id' => $this->student->id, 'name' => $this->student->name, 'role' => 'student',
            'account_status' => 'pending_activation', 'must_change_password' => true,
        ], $pending->json('data'), $pending->getContent());

        $this->app['auth']->forgetGuards();
        $teacherMe = $this->withToken($this->teacher->createToken('teacher-me')->plainTextToken)->getJson('/api/v1/me');
        $teacherMe->assertOk()->assertJsonPath('data.current_semester.id', $this->rows['semesters']['id'])
            ->assertJsonPath('data.teacher.teacher_code', 'G001');
    }

    private function otherRecord(): string
    {
        $suffix = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $studentId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $userId, 'role' => 'student', 'name' => 'Other student', 'password_hash' => 'synthetic-hash', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('students')->insert(['id' => $studentId, 'user_id' => $userId, 'nisn' => '0098765432']);
        DB::table('student_selections')->insert(['id' => $suffix, 'teacher_id' => DB::table('teachers')->value('id'), 'student_id' => $studentId, 'enrollment_id' => $this->rows['enrollments']['id'], 'semester_id' => $this->rows['semesters']['id'], 'source' => 'manual', 'expires_at' => now()->addMinutes(5)]);
        $recordId = (string) Str::uuid();
        DB::table('point_records')->insert([
            'id' => $recordId,
            'student_id' => $studentId,
            'teacher_id' => DB::table('teachers')->value('id'),
            'enrollment_id' => $this->rows['enrollments']['id'],
            'semester_id' => $this->rows['semesters']['id'],
            'selection_id' => $suffix,
            'source' => 'manual',
            'occurred_at' => now(),
            'class_snapshot' => 'Other Class',
            'major_snapshot' => 'Other Major',
            'idempotency_key' => 'other-'.$suffix,
            'request_hash' => str_repeat('f', 64),
        ]);

        return $recordId;
    }
}

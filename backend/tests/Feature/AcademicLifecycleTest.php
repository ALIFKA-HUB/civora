<?php

namespace Tests\Feature;

use App\Actions\Academic\ChangeStudentStatus;
use App\Actions\Academic\EnrollStudent;
use App\Actions\Academic\MoveStudent;
use App\Actions\Academic\OpenSemester;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\PointRecord;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\CurrentEnrollment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class AcademicLifecycleTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private Student $student;

    private Semester $semester;

    private Enrollment $oldEnrollment;

    private Classroom $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(2, 1));
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['must_change_password' => false]);
        $this->student = Student::findOrFail($this->rows['students']['id']);
        $this->student->user->update(['must_change_password' => false]);
        $this->semester = Semester::findOrFail($this->rows['semesters']['id']);
        $this->oldEnrollment = Enrollment::findOrFail($this->rows['enrollments']['id']);
        $this->oldEnrollment->update(['valid_until' => null]);
        $this->destination = Classroom::create([...$this->rows['classrooms'], 'id' => (string) Str::uuid(), 'code' => 'X-2', 'name' => 'Synthetic destination']);
    }

    private function nextSemester(): Semester
    {
        return Semester::create(['academic_year_id' => $this->semester->academic_year_id, 'name' => 'Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'draft']);
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected academic validation failure');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_draft_placement_then_open_uses_school_next_calendar_day_and_preserves_history(): void
    {
        $next = $this->nextSemester();
        $enrollment = (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01');
        $this->assertSame('2026-12-31 17:00:00', $enrollment->fresh()->valid_from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-30 17:00:00', $enrollment->fresh()->valid_until->utc()->format('Y-m-d H:i:s'));
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(18, 0));
        $opened = (new OpenSemester)->execute($this->admin, $next);
        $this->assertSame('active', $opened->status);
        $this->assertSame('closed', $this->semester->fresh()->status);
        $this->assertSame(1, Semester::where('status', 'active')->count());
        $this->assertSame($this->oldEnrollment->id, PointRecord::findOrFail($this->rows['point_records']['id'])->enrollment_id);
        $this->assertDatabaseHas('audit_logs', ['resource_id' => $next->id, 'action' => 'semester_opened']);
    }

    public function test_open_rejects_overlap_even_with_closed_semester(): void
    {
        $next = $this->nextSemester();
        $next->update(['start_date' => '2026-12-31']);
        $this->semester->update(['status' => 'closed']);
        $this->travelTo(now()->setDate(2026, 12, 31));
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $next), 'semester');
        $this->assertSame('draft', $next->fresh()->status);
    }

    public function test_open_rejects_outside_current_dates_without_closing_old_semester(): void
    {
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $this->nextSemester()), 'semester');
        $this->assertSame('active', $this->semester->fresh()->status);
    }

    public function test_open_requires_prepared_enrollment_for_every_education_active_student_including_pending_accounts(): void
    {
        $next = $this->nextSemester();
        $this->student->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $next), 'enrollment');
        $this->assertSame('active', $this->semester->fresh()->status);
    }

    public function test_open_rejects_prepared_placement_from_the_wrong_school_year(): void
    {
        $next = $this->nextSemester();
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30']);
        $this->destination->update(['academic_year_id' => $otherYear->id]);
        Enrollment::create(['student_id' => $this->student->id, 'semester_id' => $next->id, 'classroom_id' => $this->destination->id, 'valid_from' => '2027-01-01 00:00:00+07', 'valid_until' => '2027-07-01 00:00:00+07']);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $next), 'classroom');
    }

    public function test_move_closes_old_placement_at_exclusive_boundary_and_keeps_record_identity(): void
    {
        $record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $new = (new MoveStudent)->execute($this->admin, $this->student, $this->destination->id, '2026-08-01T02:01:00Z');
        $this->assertSame('2026-08-01 02:01:00', $this->oldEnrollment->fresh()->valid_until->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->oldEnrollment->id, $record->fresh()->enrollment_id);
        $current = new CurrentEnrollment;
        $this->assertSame($this->oldEnrollment->id, $current->forStudent($this->student, $this->semester, '2026-08-01T02:00:59Z')->id);
        $this->assertSame($new->id, $current->forStudent($this->student, $this->semester, '2026-08-01T02:01:00Z')->id);
        $this->assertDatabaseHas('audit_logs', ['resource_id' => $this->student->id, 'action' => 'student_moved']);
    }

    public function test_current_enrollment_respects_school_semester_bounds_even_with_legacy_unbounded_row(): void
    {
        $current = new CurrentEnrollment;
        $this->assertSame($this->oldEnrollment->id, $current->forStudent($this->student, $this->semester, '2026-12-31T16:59:59Z')->id);
        $this->assertNull($current->forStudent($this->student, $this->semester, '2026-12-31T17:00:00Z'));
        $this->assertNull($current->forStudent($this->student, $this->semester, '2026-06-30T16:59:59Z'));
    }

    public function test_calendar_uses_configured_school_timezone_and_fallback(): void
    {
        DB::table('school_settings')->update(['timezone' => 'Asia/Makassar']);
        $this->assertNull((new CurrentEnrollment)->forStudent($this->student, $this->semester, '2026-12-31T16:30:00Z'));
        DB::table('school_settings')->delete();
        $this->assertNotNull((new CurrentEnrollment)->forStudent($this->student, $this->semester, '2026-12-31T16:30:00Z'));
    }

    public static function invalidPlacements(): array
    {
        return [['inactive class', 'classroom'], ['inactive major', 'classroom'], ['wrong year', 'classroom'], ['before semester', 'effective_at'], ['after semester', 'effective_at'], ['bad timestamp', 'effective_at'], ['inactive student', 'student'], ['same class', 'classroom'], ['past record', 'effective_at']];
    }

    #[DataProvider('invalidPlacements')]
    public function test_move_rejects_invalid_placement_atomically(string $case, string $field): void
    {
        $at = '2026-08-01T02:01:00Z';
        if ($case === 'inactive class') {
            $this->destination->update(['active' => false]);
        }
        if ($case === 'inactive major') {
            DB::table('majors')->update(['active' => false]);
        }
        if ($case === 'wrong year') {
            $this->destination->update(['academic_year_id' => AcademicYear::create(['name' => 'Other', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30'])->id]);
        }
        if ($case === 'before semester') {
            $at = '2026-06-30T16:59:59Z';
        }
        if ($case === 'after semester') {
            $at = '2026-12-31T17:00:00Z';
        }
        if ($case === 'bad timestamp') {
            $at = 'not-a-date';
        }
        if ($case === 'inactive student') {
            $this->student->update(['education_status' => 'graduated']);
        }
        if ($case === 'same class') {
            $this->destination = $this->oldEnrollment->classroom;
        }
        if ($case === 'past record') {
            $at = '2026-08-01T01:59:00Z';
        }
        $this->invalid(fn () => (new MoveStudent)->execute($this->admin, $this->student, $this->destination->id, $at), $field);
        $this->assertNull($this->oldEnrollment->fresh()->valid_until);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_enroll_pending_account_in_draft_and_reject_duplicate_and_inactive_class(): void
    {
        $next = $this->nextSemester();
        $this->student->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01');
        $this->invalid(fn () => (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01'), 'enrollment');
        $this->destination->update(['active' => false]);
        $this->invalid(fn () => (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01'), 'classroom');
    }

    public function test_postgresql_rejects_overlapping_enrollments(): void
    {
        $this->expectException(QueryException::class);
        Enrollment::create(['student_id' => $this->student->id, 'semester_id' => $this->semester->id, 'classroom_id' => $this->destination->id, 'valid_from' => '2026-08-01T02:00:00Z']);
    }

    public static function terminalStatuses(): array
    {
        return [['transferred'], ['graduated']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_departure_ends_enrollment_revokes_all_access_and_retains_history(string $status): void
    {
        $user = $this->student->user;
        $token = $user->createToken('synthetic-test')->plainTextToken;
        DB::table('sessions')->insert(['id' => 'synthetic-web-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $result = (new ChangeStudentStatus)->execute($this->admin, $this->student, $status, 'Synthetic departure reason');
        $this->assertSame($status, $result->education_status);
        $this->assertSame('inactive', $user->fresh()->account_status);
        $this->assertSame('2026-08-01 02:01:00', $this->oldEnrollment->fresh()->valid_until->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->oldEnrollment->id, PointRecord::findOrFail($this->rows['point_records']['id'])->enrollment_id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('temporary_credentials', ['user_id' => $user->id]);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'active' => false]);
        $this->assertNotNull(DB::table('qr_tokens')->value('revoked_at'));
        $this->assertSame('2026-08-01 02:01:00+00', DB::table('student_selections')->value('expires_at'));
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['resource_id' => $this->student->id, 'action' => 'student_status_changed', 'reason' => 'Synthetic departure reason']);
    }

    public function test_status_change_with_invalid_reason_or_status_is_atomic(): void
    {
        $this->invalid(fn () => (new ChangeStudentStatus)->execute($this->admin, $this->student, 'active', 'Synthetic reason'), 'status');
        $this->invalid(fn () => (new ChangeStudentStatus)->execute($this->admin, $this->student, 'graduated', 'tiny'), 'reason');
        $this->assertSame('active', $this->student->fresh()->education_status);
        $this->assertNull($this->oldEnrollment->fresh()->valid_until);
    }

    public function test_departure_retains_future_draft_rows_and_open_excludes_departed_roster(): void
    {
        $next = $this->nextSemester();
        $prepared = (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01');
        $end = $prepared->valid_until;
        (new ChangeStudentStatus)->execute($this->admin, $this->student, 'transferred', 'Synthetic departure reason');
        $this->assertTrue($prepared->fresh()->valid_until->equalTo($end));
        $this->invalid(fn () => (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01'), 'student');
        $this->destination->update(['active' => false]);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('active', (new OpenSemester)->execute($this->admin, $next)->status);
        // A temporal lookup can retain the prepared row; mutation callers enforce eligibility.
        $this->assertSame($prepared->id, (new CurrentEnrollment)->forStudent($this->student, $next, '2027-01-01')->id);
    }

    public function test_departure_at_exact_enrollment_start_preserves_positive_range(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 30)->setTime(17, 0));
        (new ChangeStudentStatus)->execute($this->admin, $this->student, 'graduated', 'Synthetic departure reason');
        $this->assertNull($this->oldEnrollment->fresh()->valid_until);
        $this->assertSame('graduated', $this->student->fresh()->education_status);
        $this->assertSame('inactive', $this->student->user->fresh()->account_status);
    }

    public function test_open_rejects_placement_that_extends_past_school_semester_end(): void
    {
        $next = $this->nextSemester();
        Enrollment::create(['student_id' => $this->student->id, 'semester_id' => $next->id, 'classroom_id' => $this->destination->id, 'valid_from' => '2027-01-01']);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $next), 'enrollment');
    }

    public function test_enroll_rejects_semester_outside_academic_year(): void
    {
        $next = $this->nextSemester();
        $next->update(['end_date' => '2027-07-31']);
        $this->invalid(fn () => (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01'), 'semester');
    }

    public function test_move_requires_existing_enrollment_and_open_rejects_closed_semester(): void
    {
        $this->invalid(fn () => (new MoveStudent)->execute($this->admin, $this->student, $this->destination->id, '2026-07-01'), 'enrollment');
        $this->semester->update(['status' => 'closed']);
        $this->invalid(fn () => (new OpenSemester)->execute($this->admin, $this->semester), 'semester');
        $this->invalid(fn () => (new EnrollStudent)->execute($this->admin, $this->student, $this->semester, $this->destination->id, '2026-08-01'), 'semester');
    }

    public function test_invalid_classroom_identifier_is_validation_failure_without_mutation(): void
    {
        $this->invalid(fn () => (new MoveStudent)->execute($this->admin, $this->student, 'not-a-uuid', '2026-08-01T02:01:00Z'), 'classroom');
        $this->assertNull($this->oldEnrollment->fresh()->valid_until);
    }

    public function test_pending_account_student_can_move_while_education_active(): void
    {
        $this->student->user->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $new = (new MoveStudent)->execute($this->admin, $this->student, $this->destination->id, '2026-08-01T02:01:00Z');
        $this->assertSame($this->destination->id, $new->classroom_id);
    }

    public function test_open_failure_after_closing_old_semester_rolls_back_both_and_audit(): void
    {
        $next = $this->nextSemester();
        (new EnrollStudent)->execute($this->admin, $this->student, $next, $this->destination->id, '2027-01-01');
        $this->travelTo(now()->setDate(2027, 1, 1));
        AuditLog::creating(function ($audit): void {
            if ($audit->action === 'semester_opened') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            (new OpenSemester)->execute($this->admin, $next);
            $this->fail('Expected synthetic audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame('active', $this->semester->fresh()->status);
        $this->assertSame('draft', $next->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'semester_closed']);
    }

    public static function unauthorizedActors(): array
    {
        $cases = [];
        foreach (['open', 'enroll', 'move', 'status'] as $action) {
            foreach (['teacher', 'inactive', 'must_change'] as $actor) {
                $cases[] = [$action, $actor];
            }
        }

        return $cases;
    }

    #[DataProvider('unauthorizedActors')]
    public function test_actions_recheck_stale_actor_from_database(string $action, string $actor): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(match ($actor) {
            'teacher' => ['role' => 'teacher'], 'inactive' => ['account_status' => 'inactive'], 'must_change' => ['must_change_password' => true],
        });
        $this->expectException(AuthorizationException::class);
        match ($action) {
            'open' => (new OpenSemester)->execute($this->admin, $this->semester),
            'enroll' => (new EnrollStudent)->execute($this->admin, $this->student, $this->semester, $this->destination->id, '2026-08-01T02:00:00Z'),
            'move' => (new MoveStudent)->execute($this->admin, $this->student, $this->destination->id, '2026-08-01T02:00:00Z'),
            'status' => (new ChangeStudentStatus)->execute($this->admin, $this->student, 'graduated', 'Synthetic reason'),
        };
    }
}

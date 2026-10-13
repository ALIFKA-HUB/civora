<?php

namespace Tests\Feature;

use App\Actions\Points\RedeemStudentQr;
use App\Actions\Points\SelectStudentManually;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class QrSelectionTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $studentUser;

    private User $teacherUser;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->freezeTime();
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(9, 0)->setTimezone('Asia/Jakarta'));
        $this->studentUser = User::findOrFail(DB::table('students')->value('user_id'));
        $this->teacherUser = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->studentUser->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacherUser->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->student = Student::firstOrFail();
    }

    private function issuingToken(): string
    {
        return $this->studentUser->createToken('student-device')->plainTextToken;
    }

    public function test_student_qr_is_private_short_lived_and_rotating(): void
    {
        $token = $this->issuingToken();
        $first = $this->withToken($token)->postJson('/api/v1/student/qr')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'server_time']]);
        self::assertEquals(20, CarbonImmutable::parse($first->json('data.server_time'))->diffInSeconds(CarbonImmutable::parse($first->json('data.expires_at'))));
        $firstToken = $first->json('data.token');
        self::assertMatchesRegularExpression('/^civora1_[A-Za-z0-9_-]{43}$/', $firstToken);
        self::assertSame(hash('sha256', $firstToken), DB::table('qr_tokens')->where('student_id', $this->student->id)->latest('issued_at')->value('token_hash'));
        self::assertSame(1, DB::table('qr_tokens')->where('student_id', $this->student->id)->whereNull('revoked_at')->count());

        $this->travel(19)->seconds();
        $teacher = User::findOrFail($this->teacherUser->id);
        $selection = app(RedeemStudentQr::class)->execute($teacher, $firstToken, 'scan-first');
        self::assertSame($this->student->id, $selection->student_id);
        self::assertSame(300, $selection->expires_at->getTimestamp() - now()->getTimestamp());

        $this->travelTo(now()->addSecond());
        $second = $this->withToken($token)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        self::assertNotSame($firstToken, $second);
        self::assertSame(1, DB::table('qr_tokens')->where('student_id', $this->student->id)->whereNull('revoked_at')->count());
        self::assertNotNull(DB::table('qr_tokens')->where('token_hash', hash('sha256', $firstToken))->value('revoked_at'));
    }

    public function test_qr_expiration_is_exactly_twenty_seconds_and_selection_lasts_five_minutes(): void
    {
        $token = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $issuedAt = now();
        $this->travel(20)->seconds();
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $token, 'expired-scan');
            self::fail('An expired QR must not be redeemable.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }

        $this->travelTo($issuedAt);
        $token = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $selection = app(RedeemStudentQr::class)->execute($this->teacherUser, $token, 'fresh-scan');
        self::assertSame(300, $selection->expires_at->getTimestamp() - now()->getTimestamp());
    }

    public function test_manual_and_qr_selection_use_same_current_enrollment_and_manual_accepts_pending_student(): void
    {
        $manual = app(SelectStudentManually::class)->execute($this->teacherUser, $this->student);
        $token = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $qr = app(RedeemStudentQr::class)->execute($this->teacherUser, $token, 'same-student');

        self::assertSame($manual->student_id, $qr->student_id);
        self::assertSame($manual->enrollment_id, $qr->enrollment_id);
        self::assertSame($manual->semester_id, $qr->semester_id);

        $this->studentUser->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $pending = app(SelectStudentManually::class)->execute($this->teacherUser, $this->student);
        self::assertSame($this->student->id, $pending->student_id);
        $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertForbidden();
        $this->studentUser->update(['account_status' => 'inactive']);
        try {
            app(SelectStudentManually::class)->execute($this->teacherUser, $this->student);
            self::fail('An inactive account must not be selected manually.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('student', $exception->errors());
        }
    }

    public function test_qr_from_revoked_issuing_session_is_rejected(): void
    {
        $plain = $this->issuingToken();
        $raw = $this->withToken($plain)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $this->studentUser->tokens()->delete();

        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'revoked-session');
            self::fail('A QR from a revoked issuing session must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }
    }

    public function test_completed_retry_survives_qr_expiry_but_rejects_a_revoked_issuing_session(): void
    {
        $plain = $this->issuingToken();
        $raw = $this->withToken($plain)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $first = app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'completed-retry');
        $this->travel(20)->seconds();
        self::assertSame($first->id, app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'completed-retry')->id);

        $this->studentUser->tokens()->delete();
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'completed-retry');
            self::fail('A retry after its issuing session is revoked must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }
    }

    public function test_completed_retry_rejects_expired_selection_and_changed_enrollment(): void
    {
        $raw = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'selection-expiry');
        $this->travel(5)->minutes();
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'selection-expiry');
            self::fail('An expired selection must not be returned as a successful retry.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }
    }

    public function test_completed_retry_rejects_when_current_enrollment_changes(): void
    {
        $raw = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'enrollment-change');
        DB::table('enrollments')->where('student_id', $this->student->id)->update(['valid_until' => now()->addMinute()]);
        $this->travel(61)->seconds();
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'enrollment-change');
            self::fail('A retry cannot return a selection for a no-longer-current enrollment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('student', $exception->errors());
        }
    }

    public function test_completed_retry_rejects_when_active_semester_changes(): void
    {
        $raw = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'semester-change');
        DB::table('semesters')->where('status', 'active')->update(['status' => 'closed']);
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'semester-change');
            self::fail('A retry cannot return a selection after its semester closes.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('semester', $exception->errors());
        }
    }

    public function test_teacher_endpoints_search_profile_and_create_manual_selection(): void
    {
        $teacher = $this->teacherUser->createToken('teacher-device')->plainTextToken;
        $this->withToken($teacher)->getJson('/api/v1/teacher/students?q=Synthetic&per_page=10')
            ->assertOk()->assertJsonPath('data.0.id', $this->student->id)->assertJsonPath('meta.current_page', 1);
        $this->withToken($teacher)->getJson('/api/v1/teacher/students/'.$this->student->id)
            ->assertOk()->assertJsonPath('data.nisn', '0012345678')
            ->assertJsonPath('data.totals.achievement', 10)->assertJsonPath('data.totals.violation', 0);
        $this->withToken($teacher)->postJson('/api/v1/teacher/student-selections', ['student_id' => $this->student->id])
            ->assertCreated()->assertJsonPath('data.source', 'manual');
    }

    public function test_retry_is_idempotent_only_for_same_teacher_request_key_and_token(): void
    {
        $raw = $this->withToken($this->issuingToken())->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $first = app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'retry-key');
        $retry = app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'retry-key');
        self::assertSame($first->id, $retry->id);
        $this->withToken($this->studentUser->createToken('second-device')->plainTextToken)->postJson('/api/v1/student/qr')->assertOk();
        self::assertSame($first->id, app(RedeemStudentQr::class)->execute($this->teacherUser, $raw, 'retry-key')->id);
        $newQr = $this->withToken($this->studentUser->createToken('third-device')->plainTextToken)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        try {
            app(RedeemStudentQr::class)->execute($this->teacherUser, $newQr, 'retry-key');
            self::fail('A request key cannot be reused with another QR token.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('request_key', $exception->errors());
        }

        $anotherTeacher = User::create(['role' => 'teacher', 'name' => 'Another teacher', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $anotherTeacher->id, 'teacher_code' => 'G002']);
        try {
            app(RedeemStudentQr::class)->execute($anotherTeacher, $raw, 'other-key');
            self::fail('A consumed QR belongs to only one teacher and request key.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('token', $exception->errors());
        }
    }
}

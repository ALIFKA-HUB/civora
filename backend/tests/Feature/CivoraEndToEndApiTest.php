<?php

namespace Tests\Feature;

use App\Actions\Academic\ChangeStudentStatus;
use App\Actions\Reviews\DecideReviewRequest;
use App\Jobs\DispatchCivoraNotifications;
use App\Models\Attachment;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\ReviewRequest;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class CivoraEndToEndApiTest extends TestCase
{
    use DatabaseMigrations, FoundationFixture;

    private User $admin;

    private User $studentUser;

    private User $teacherUser;

    private Student $student;

    private string $studentToken;

    private string $teacherToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(9, 0)->setTimezone('Asia/Jakarta'));

        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->studentUser = User::findOrFail(DB::table('students')->value('user_id'));
        $this->teacherUser = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->student = Student::findOrFail($this->rows['students']['id']);
        foreach ([$this->admin, $this->studentUser, $this->teacherUser] as $user) {
            $user->update(['account_status' => 'active', 'must_change_password' => false]);
        }

        DB::table('review_requests')->delete();
        DB::table('notifications')->delete();
        DB::table('outbox_events')->delete();
        DB::table('device_tokens')->delete();
        $this->studentToken = $this->studentUser->createToken('synthetic-student-device')->plainTextToken;
        $this->teacherToken = $this->teacherUser->createToken('synthetic-teacher-device')->plainTextToken;
    }

    public function test_api_record_objection_admin_correction_history_summary_and_outbox_delivery(): void
    {
        $violation = $this->pointVersion('V001', 'violation', 'Synthetic Violation', 15);
        $corrected = $this->pointVersion('V002', 'violation', 'Synthetic Corrected Violation', 5);
        $qr = $this->asApiToken($this->studentToken)->postJson('/api/v1/student/qr')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->json('data.token');
        $selection = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/qr/redeem', [
            'token' => $qr, 'request_key' => 'i01-record-scan',
        ])->assertCreated()->json('data.id');
        $body = [
            'selection_id' => $selection,
            'point_type_version_id' => $violation,
            'description' => 'Catatan pelanggaran sintetis untuk QA.',
        ];

        // Intentionally ignore the first HTTP response to model a committed save whose reply is lost.
        $this->asApiToken($this->teacherToken)->withHeader('Idempotency-Key', 'i01-lost-response-record')->postJson('/api/v1/teacher/records', $body);
        $savedId = DB::table('point_records')->where('idempotency_key', 'i01-lost-response-record')->value('id');
        self::assertNotNull($savedId);
        $retry = $this->asApiToken($this->teacherToken)->withHeader('Idempotency-Key', 'i01-lost-response-record')
            ->postJson('/api/v1/teacher/records', $body)->assertOk();
        self::assertSame($savedId, $retry->json('data.id'));
        self::assertSame(1, DB::table('point_records')->where('idempotency_key', 'i01-lost-response-record')->count());
        self::assertSame(15, $retry->json('summary.violation'));

        $event = OutboxEvent::where('event_key', 'point-record-created:'.$savedId)->firstOrFail();
        self::assertNull($event->processed_at);
        self::assertSame(0, Notification::where('event_key', $event->event_key)->count());
        self::assertDatabaseHas('point_records', ['id' => $savedId]);

        $objection = $this->asApiToken($this->studentToken)->postJson('/api/v1/student/records/'.$savedId.'/objections', [
            'reason' => 'Mohon tinjau nilai catatan ini.',
        ])->assertCreated()->assertJsonPath('data.kind', 'student_objection')->json('data.id');
        $review = ReviewRequest::findOrFail($objection);
        app(DecideReviewRequest::class)->execute(
            $this->admin,
            $review,
            'approve',
            'Nilai diperbaiki setelah pemeriksaan bukti.',
            1,
            ['operation' => 'revise', 'point_type_version_id' => $corrected],
        );

        $summary = $this->asApiToken($this->studentToken)->getJson('/api/v1/student/summary')->assertOk();
        $summary->assertJsonPath('data.totals.achievement', 10)->assertJsonPath('data.totals.violation', 5);
        $studentHistory = $this->asApiToken($this->studentToken)->getJson('/api/v1/student/records')->assertOk();
        self::assertSame(2, $studentHistory->json('meta.total'));
        $historyRecord = collect($studentHistory->json('data'))->firstWhere('id', $savedId);
        self::assertSame(5, $historyRecord['latest_revision']['points']);
        self::assertSame('Synthetic Corrected Violation', $historyRecord['latest_revision']['name']);
        $this->asApiToken($this->teacherToken)->getJson('/api/v1/teacher/records/'.$savedId)
            ->assertOk()->assertJsonPath('data.latest_revision.points', 5);
        $this->asApiToken($this->teacherToken)->getJson('/api/v1/teacher/students/'.$this->student->id.'/records')
            ->assertOk()->assertJsonPath('meta.total', 2);

        config(['queue.default' => 'database']);
        DispatchCivoraNotifications::dispatch($event->id);
        self::assertNull($event->fresh()->processed_at);
        self::assertSame(1, DB::table('jobs')->count());
        $this->runOutboxWorker();
        self::assertNotNull($event->fresh()->processed_at);
        self::assertSame(1, Notification::where('event_key', $event->event_key)->count());
        DispatchCivoraNotifications::dispatch($event->id);
        $this->runOutboxWorker();
        self::assertSame(1, Notification::where('event_key', $event->event_key)->count());
        self::assertSame(10, $summary->json('data.totals.achievement'));
        self::assertSame(5, $summary->json('data.totals.violation'));
    }

    public function test_qr_replay_expiry_and_selection_conflicts_after_placement_or_semester_change(): void
    {
        $version = $this->pointVersion('V003', 'violation', 'Synthetic Conflict Violation', 15);
        $qr = $this->asApiToken($this->studentToken)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $payload = ['token' => $qr, 'request_key' => 'i01-replay-key'];
        $first = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/qr/redeem', $payload)->assertCreated();
        $replay = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/qr/redeem', $payload)->assertCreated();
        self::assertSame($first->json('data.id'), $replay->json('data.id'));
        self::assertSame(1, DB::table('qr_redemptions')->where('request_key', 'i01-replay-key')->count());
        $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/qr/redeem', ['token' => $qr, 'request_key' => 'i01-second-teacher-key'])
            ->assertUnprocessable();

        $expiredQr = $this->asApiToken($this->studentToken)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');
        $this->travel(20)->seconds();
        $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/qr/redeem', ['token' => $expiredQr, 'request_key' => 'i01-expired-qr'])
            ->assertUnprocessable();
        $this->travelTo(now()->subSeconds(20));

        $placementSelection = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/student-selections', [
            'student_id' => $this->student->id,
        ])->assertCreated()->json('data.id');
        DB::table('enrollments')->where('id', $this->rows['enrollments']['id'])->update(['valid_until' => now()->subSecond()]);
        $this->asApiToken($this->teacherToken)->withHeader('Idempotency-Key', 'i01-placement-conflict')->postJson('/api/v1/teacher/records', [
            'selection_id' => $placementSelection, 'point_type_version_id' => $version, 'description' => 'Record after placement changed.',
        ])->assertStatus(409);
        self::assertDatabaseMissing('point_records', ['idempotency_key' => 'i01-placement-conflict']);

        DB::table('enrollments')->where('id', $this->rows['enrollments']['id'])->update(['valid_until' => '2026-12-01 00:00:00+07']);
        $semesterSelection = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/student-selections', [
            'student_id' => $this->student->id,
        ])->assertCreated()->json('data.id');
        Semester::whereKey($this->rows['semesters']['id'])->update(['status' => 'closed']);
        $this->asApiToken($this->teacherToken)->withHeader('Idempotency-Key', 'i01-semester-conflict')->postJson('/api/v1/teacher/records', [
            'selection_id' => $semesterSelection, 'point_type_version_id' => $version, 'description' => 'Record after semester changed.',
        ])->assertUnprocessable();
        self::assertDatabaseMissing('point_records', ['idempotency_key' => 'i01-semester-conflict']);
    }

    public function test_archived_student_cannot_redeem_qr_but_teacher_keeps_owned_history(): void
    {
        $this->asApiToken($this->studentToken)->postJson('/api/v1/student/qr')->assertOk();
        app(ChangeStudentStatus::class)->execute($this->admin, $this->student, 'graduated', 'Synthetic graduation for I01 QA.');

        $this->asApiToken($this->studentToken)->postJson('/api/v1/student/qr')->assertUnauthorized();
        $this->asApiToken($this->teacherToken)->getJson('/api/v1/teacher/records/'.$this->rows['point_records']['id'])
            ->assertOk()->assertJsonPath('data.id', $this->rows['point_records']['id']);
        $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/student-selections', ['student_id' => $this->student->id])
            ->assertUnprocessable();
    }

    public function test_record_api_rejects_an_attachment_owned_by_another_account(): void
    {
        $version = $this->pointVersion('V004', 'violation', 'Synthetic Attachment Violation', 15);
        $selection = $this->asApiToken($this->teacherToken)->postJson('/api/v1/teacher/student-selections', [
            'student_id' => $this->student->id,
        ])->assertCreated()->json('data.id');
        $attachment = Attachment::create([
            'owner_user_id' => $this->admin->id,
            'private_path' => 'evidence/i01-other-owner.png',
            'mime_type' => 'image/png',
            'size' => 64,
            'checksum' => str_repeat('d', 64),
        ]);

        $this->asApiToken($this->teacherToken)->withHeader('Idempotency-Key', 'i01-cross-attachment')->postJson('/api/v1/teacher/records', [
            'selection_id' => $selection,
            'point_type_version_id' => $version,
            'description' => 'Record with another owner attachment.',
            'attachment_id' => $attachment->id,
        ])->assertUnprocessable();
        self::assertDatabaseMissing('point_records', ['idempotency_key' => 'i01-cross-attachment']);
    }

    private function pointVersion(string $code, string $category, string $name, int $points): string
    {
        $typeId = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $typeId, 'code' => $code, 'category' => $category, 'active' => true]);
        $versionId = (string) Str::uuid();
        DB::table('point_type_versions')->insert([
            'id' => $versionId,
            'point_type_id' => $typeId,
            'version' => 1,
            'name' => $name,
            'description' => 'Synthetic I01 test point type.',
            'points' => $points,
            'created_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        return $versionId;
    }

    private function asApiToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function runOutboxWorker(): void
    {
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ];
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--once', '--queue=default', '--tries=1'], base_path(), $environment);
        $worker->run();

        self::assertSame(0, $worker->getExitCode(), 'Outbox worker failed: '.$worker->getErrorOutput());
    }
}

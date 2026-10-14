<?php

namespace Tests\Feature;

use App\Actions\Academic\MoveStudent;
use App\Models\Attachment;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class RecordPointTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacher->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    public function test_record_retry_returns_same_record_and_rejects_payload_conflict(): void
    {
        $selection = $this->selection();
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $body = ['selection_id' => $selection, 'point_type_version_id' => $this->rows['point_type_versions']['id'], 'description' => 'This is a meaningful note'];
        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'record-attempt-123')->postJson('/api/v1/teacher/records', $body)->assertCreated();
        $retry = $this->withToken($token)->withHeader('Idempotency-Key', 'record-attempt-123')->postJson('/api/v1/teacher/records', $body)->assertOk();

        self::assertSame($first->json('data.id'), $retry->json('data.id'));
        self::assertSame(20, $first->json('summary.achievement'));
        self::assertSame('Synthetic Achievement', $first->json('data.latest_revision.name'));
        self::assertSame(1, DB::table('point_records')->where('teacher_id', DB::table('teachers')->value('id'))->where('idempotency_key', 'record-attempt-123')->count());
        $this->withToken($token)->withHeader('Idempotency-Key', 'record-attempt-123')->postJson('/api/v1/teacher/records', [...$body, 'description' => 'A different meaningful note'])->assertStatus(409);
        $this->withToken($token)->withHeader('Idempotency-Key', 'new-key-same-selection')->postJson('/api/v1/teacher/records', $body)->assertUnprocessable();
        self::assertDatabaseHas('outbox_events', ['event_key' => 'point-record-created:'.$first->json('data.id')]);
        self::assertDatabaseHas('audit_logs', ['action' => 'point_record_created', 'resource_id' => $first->json('data.id')]);
    }

    public function test_record_rejects_authority_spoofing_expired_used_and_stale_catalog_inputs(): void
    {
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $valid = ['point_type_version_id' => $this->rows['point_type_versions']['id'], 'description' => 'This is a meaningful note'];
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-spoof-123')->postJson('/api/v1/teacher/records', [...$valid, 'selection_id' => $this->selection(), 'student_id' => $this->rows['students']['id'], 'points' => 999])->assertUnprocessable();

        $expired = $this->selection(now()->subSecond());
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-expired-123')->postJson('/api/v1/teacher/records', [...$valid, 'selection_id' => $expired])->assertUnprocessable();

        $used = $this->selection();
        DB::table('student_selections')->where('id', $used)->update(['consumed_at' => now()]);
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-used-123')->postJson('/api/v1/teacher/records', [...$valid, 'selection_id' => $used])->assertUnprocessable();

        $staleSelection = $this->selection();
        $newVersion = (string) Str::uuid();
        DB::table('point_type_versions')->insert(['id' => $newVersion, 'point_type_id' => $this->rows['point_types']['id'], 'version' => 2, 'name' => 'Updated point type', 'description' => 'Updated description', 'points' => 20, 'created_by' => $this->rows['users']['id'], 'created_at' => now()]);
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-stale-123')->postJson('/api/v1/teacher/records', [...$valid, 'selection_id' => $staleSelection])->assertStatus(409);
    }

    public function test_attempt_lookup_only_returns_own_teacher_record(): void
    {
        $selection = $this->selection();
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-lookup-123')->postJson('/api/v1/teacher/records', [
            'selection_id' => $selection,
            'point_type_version_id' => $this->rows['point_type_versions']['id'],
            'description' => 'This is a meaningful note',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/teacher/record-attempts/attempt-lookup-123')->assertOk()->assertJsonPath('data.id', DB::table('point_records')->where('idempotency_key', 'attempt-lookup-123')->value('id'));
        $other = User::create(['role' => 'teacher', 'name' => 'Other teacher', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $other->id, 'teacher_code' => 'G002']);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/teacher/record-attempts/attempt-lookup-123')->assertNotFound();
    }

    public function test_description_bounds_attachment_owner_and_pending_teacher_are_enforced(): void
    {
        $selection = $this->selection();
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $base = ['selection_id' => $selection, 'point_type_version_id' => $this->rows['point_type_versions']['id']];
        $this->withToken($token)->withHeader('Idempotency-Key', 'description-short')->postJson('/api/v1/teacher/records', [...$base, 'description' => 'four'])->assertUnprocessable();
        $this->withToken($token)->withHeader('Idempotency-Key', 'description-long')->postJson('/api/v1/teacher/records', [...$base, 'description' => str_repeat('x', 1001)])->assertUnprocessable();

        $admin = User::findOrFail($this->rows['users']['id']);
        $attachment = Attachment::create(['owner_user_id' => $admin->id, 'private_path' => 'evidence/other-owner.png', 'mime_type' => 'image/png', 'size' => 64, 'checksum' => str_repeat('e', 64)]);
        $this->withToken($token)->withHeader('Idempotency-Key', 'wrong-attachment-owner')->postJson('/api/v1/teacher/records', [...$base, 'description' => 'Valid point description', 'attachment_id' => $attachment->id])->assertUnprocessable();

        $this->teacher->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $this->withToken($token)->withHeader('Idempotency-Key', 'pending-teacher')->postJson('/api/v1/teacher/records', [...$base, 'description' => 'Valid point description'])->assertForbidden();
        self::assertSame(0, DB::table('point_records')->where('idempotency_key', 'wrong-attachment-owner')->count());
    }

    public function test_record_rejects_a_selection_after_student_placement_changes(): void
    {
        $selection = $this->selection();
        $admin = User::findOrFail($this->rows['users']['id']);
        $admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $destination = Classroom::create([...$this->rows['classrooms'], 'id' => (string) Str::uuid(), 'code' => 'X-2', 'name' => 'Moved Class']);
        app(MoveStudent::class)->execute($admin, Student::findOrFail($this->rows['students']['id']), $destination->id, now()->toIso8601String());

        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $this->withToken($token)->withHeader('Idempotency-Key', 'attempt-after-move-123')->postJson('/api/v1/teacher/records', [
            'selection_id' => $selection,
            'point_type_version_id' => $this->rows['point_type_versions']['id'],
            'description' => 'This is a meaningful note',
        ])->assertStatus(409);
        self::assertSame(0, DB::table('point_records')->where('idempotency_key', 'attempt-after-move-123')->count());
    }

    private function selection(?\DateTimeInterface $expires = null): string
    {
        $id = (string) Str::uuid();
        DB::table('student_selections')->insert([
            'id' => $id,
            'teacher_id' => DB::table('teachers')->value('id'),
            'student_id' => $this->rows['students']['id'],
            'enrollment_id' => $this->rows['enrollments']['id'],
            'semester_id' => $this->rows['semesters']['id'],
            'source' => 'manual',
            'expires_at' => $expires ?? now()->addMinutes(5),
        ]);

        return $id;
    }
}

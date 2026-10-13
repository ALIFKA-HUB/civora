<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class PrivateEvidenceTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        Storage::fake('local');
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacher->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    public function test_stage_image_reencodes_to_private_storage_and_enforces_owner_on_download(): void
    {
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $upload = UploadedFile::fake()->image('proof.png', 1, 1);
        $response = $this->withToken($token)->postJson('/api/v1/teacher/attachments', ['file' => $upload])->assertCreated();
        $attachmentId = $response->json('data.id');
        $attachment = DB::table('attachments')->where('id', $attachmentId)->first();

        self::assertSame($this->teacher->id, $attachment->owner_user_id);
        self::assertStringNotContainsString('private_path', $response->getContent());
        self::assertTrue(Storage::disk('local')->exists($attachment->private_path));
        $this->withToken($token)->get('/api/v1/attachments/'.$attachmentId)->assertOk();

        $other = User::findOrFail($this->rows['users']['id']);
        self::assertSame('admin', $other->role);
        self::assertNotSame($other->id, $attachment->owner_user_id);
        $other->update(['account_status' => 'active', 'must_change_password' => false]);
        $other->createToken('admin');
        $this->actingAs($other, 'sanctum')->get('/api/v1/attachments/'.$attachmentId)->assertOk();
    }

    public function test_upload_rejects_non_image_and_oversized_files(): void
    {
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/teacher/attachments', ['file' => UploadedFile::fake()->createWithContent('proof.jpg', 'not an image')])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/teacher/attachments', ['file' => UploadedFile::fake()->image('large.png')->size(5121)])->assertUnprocessable();
    }

    public function test_jpeg_is_accepted_and_client_filename_is_not_stored(): void
    {
        $token = $this->teacher->createToken('teacher')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/teacher/attachments', ['file' => UploadedFile::fake()->image('client-name.jpg', 2, 2)->mimeType('image/jpeg')])->assertCreated();
        $attachment = DB::table('attachments')->where('id', $response->json('data.id'))->first();

        self::assertSame('image/jpeg', $attachment->mime_type);
        self::assertStringNotContainsString('client-name', $attachment->private_path);
    }

    public function test_active_teacher_can_view_an_active_students_record_evidence_without_recording_it(): void
    {
        $path = $this->rows['attachments']['private_path'];
        Storage::disk('local')->put($path, 'synthetic private image');
        $teacher = User::create(['role' => 'teacher', 'name' => 'Eligible non-recorder', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $teacher->id, 'teacher_code' => 'G002']);

        $this->actingAs($teacher, 'sanctum')->get('/api/v1/attachments/'.$this->rows['attachments']['id'])->assertOk();
    }

    public function test_student_can_view_only_evidence_attached_to_their_own_record(): void
    {
        $attachmentId = $this->rows['attachments']['id'];
        Storage::disk('local')->put($this->rows['attachments']['private_path'], 'synthetic private image');
        $student = User::findOrFail(DB::table('students')->value('user_id'));
        $student->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->actingAs($student, 'sanctum')->get('/api/v1/attachments/'.$attachmentId)->assertOk();

        $other = User::create([
            'role' => 'student', 'name' => 'Different synthetic student',
            'password_hash' => 'Synthetic password', 'account_status' => 'active',
            'must_change_password' => false,
        ]);
        DB::table('students')->insert(['id' => (string) Str::uuid(), 'user_id' => $other->id, 'nisn' => '0088776655']);
        $this->actingAs($other, 'sanctum')->get('/api/v1/attachments/'.$attachmentId)->assertForbidden();
    }

    public function test_pending_and_inactive_accounts_cannot_stream_staged_or_linked_evidence(): void
    {
        $staged = Attachment::create([
            'owner_user_id' => $this->teacher->id,
            'private_path' => 'evidence/staged-policy-test.png',
            'mime_type' => 'image/png',
            'size' => 64,
            'checksum' => str_repeat('f', 64),
        ]);
        $linked = Attachment::create([
            'owner_user_id' => $this->teacher->id,
            'private_path' => 'evidence/linked-policy-test.png',
            'mime_type' => 'image/png',
            'size' => 64,
            'checksum' => str_repeat('d', 64),
        ]);
        Storage::disk('local')->put($staged->private_path, 'staged bytes');
        Storage::disk('local')->put($linked->private_path, 'linked bytes');
        DB::table('record_revisions')->insert([
            'id' => (string) Str::uuid(),
            'point_record_id' => $this->rows['point_records']['id'],
            'revision_number' => 2,
            'point_type_version_id' => $this->rows['point_type_versions']['id'],
            'points_snapshot' => 10,
            'name_snapshot' => 'Synthetic Achievement',
            'category_snapshot' => 'achievement',
            'description' => 'Linked evidence test',
            'attachment_id' => $linked->id,
            'status' => 'active',
            'changed_by' => $this->teacher->id,
            'reason' => 'Synthetic evidence revision',
            'created_at' => now(),
        ]);

        $this->teacher->update(['account_status' => 'pending_activation', 'must_change_password' => true]);
        $pending = $this->teacher->fresh();
        $this->actingAs($pending, 'sanctum')->get('/api/v1/attachments/'.$staged->id)->assertForbidden();
        $this->actingAs($pending, 'sanctum')->get('/api/v1/attachments/'.$linked->id)->assertForbidden();

        $this->teacher->update(['account_status' => 'inactive']);
        $this->actingAs($this->teacher->fresh(), 'sanctum')->get('/api/v1/attachments/'.$staged->id)->assertUnauthorized();
    }
}

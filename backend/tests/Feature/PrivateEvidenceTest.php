<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/attachments/'.$attachmentId)->assertNotFound();
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
}

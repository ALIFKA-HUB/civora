<?php

namespace Tests\Feature;

use App\Actions\Catalog\CreatePointType;
use App\Actions\Catalog\DeactivatePointType;
use App\Actions\Catalog\RevisePointType;
use App\Models\PointType;
use App\Models\PointTypeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class PointCatalogTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->admin = User::findOrFail($this->rows['users']['id']);
        $this->admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $this->teacher->update(['account_status' => 'active', 'must_change_password' => false]);
        $this->student = User::findOrFail(DB::table('students')->value('user_id'));
        $this->student->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'code' => 'A002',
            'category' => 'achievement',
            'name' => 'Synthetic award',
            'points' => 15,
            'description' => 'Synthetic catalog description',
        ], $overrides);
    }

    public function test_admin_creates_active_type_with_first_version_and_audit(): void
    {
        $type = app(CreatePointType::class)->execute($this->admin, $this->attributes());
        $version = $type->pointTypeVersions()->firstOrFail();

        self::assertTrue($type->active);
        self::assertSame(1, $version->version);
        self::assertSame(15, $version->points);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $this->admin->id, 'resource_id' => $type->id, 'action' => 'point_type_created']);
    }

    public function test_create_whitelist_does_not_allow_client_to_deactivate_a_type(): void
    {
        try {
            app(CreatePointType::class)->execute($this->admin, $this->attributes(['active' => false]));
            self::fail('Expected disallowed field validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('active', $exception->errors());
        }
        self::assertSame(1, PointType::count());
    }

    public function test_unused_type_category_can_change_before_it_is_used(): void
    {
        $type = app(CreatePointType::class)->execute($this->admin, $this->attributes());
        $version = app(RevisePointType::class)->execute($this->admin, $type, [
            'category' => 'violation', 'name' => 'Synthetic violation', 'points' => 5, 'description' => 'Synthetic category change',
        ]);

        self::assertSame(2, $version->version);
        self::assertSame('violation', $type->fresh()->category);
    }

    public function test_admin_revision_appends_a_version_and_preserves_old_version_and_record_snapshot(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);
        $oldVersion = PointTypeVersion::findOrFail($this->rows['point_type_versions']['id']);
        $newVersion = app(RevisePointType::class)->execute($this->admin, $type, [
            'name' => 'Revised achievement',
            'points' => 15,
            'description' => 'Revised synthetic description',
        ]);

        self::assertSame(2, $newVersion->version);
        self::assertSame(15, $newVersion->points);
        self::assertSame(10, $oldVersion->fresh()->points);
        self::assertSame(10, DB::table('record_revisions')->where('id', $this->rows['record_revisions']['id'])->value('points_snapshot'));
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $this->admin->id, 'resource_id' => $type->id, 'action' => 'point_type_revised']);
    }

    public function test_catalog_creation_rejects_nonpositive_points(): void
    {
        foreach ([0, -1] as $points) {
            try {
                app(CreatePointType::class)->execute($this->admin, $this->attributes(['points' => $points]));
                self::fail('Expected point validation failure.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('points', $exception->errors());
            }
        }
    }

    public function test_revision_rejects_nonpositive_points_without_appending_a_version(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);
        foreach ([0, -1] as $points) {
            try {
                app(RevisePointType::class)->execute($this->admin, $type, [
                    'name' => 'Invalid revision', 'points' => $points, 'description' => 'Invalid points test',
                ]);
                self::fail('Expected point validation failure.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('points', $exception->errors());
            }
        }
        self::assertSame(1, $type->pointTypeVersions()->count());
    }

    public function test_teacher_cannot_create_revise_or_deactivate_catalog_types(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);

        foreach ([
            fn () => app(CreatePointType::class)->execute($this->teacher, $this->attributes()),
            fn () => app(RevisePointType::class)->execute($this->teacher, $type, ['name' => 'Changed', 'points' => 12, 'description' => 'Changed description']),
            fn () => app(DeactivatePointType::class)->execute($this->teacher, $type),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected authorization failure.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, $type->pointTypeVersions()->count());
        self::assertTrue($type->fresh()->active);
    }

    public function test_pending_admin_cannot_mutate_catalog(): void
    {
        $pending = User::create(['role' => 'admin', 'name' => 'Pending admin', 'password_hash' => 'Synthetic password phrase', 'account_status' => 'pending_activation', 'must_change_password' => true]);

        $this->expectException(AuthorizationException::class);
        app(CreatePointType::class)->execute($pending, $this->attributes());
    }

    public function test_inactive_admin_cannot_deactivate_catalog(): void
    {
        $this->admin->update(['account_status' => 'inactive']);
        $type = PointType::findOrFail($this->rows['point_types']['id']);

        $this->expectException(AuthorizationException::class);
        app(DeactivatePointType::class)->execute($this->admin, $type);
    }

    public function test_category_cannot_change_after_any_record_revision_uses_the_type(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);

        try {
            app(RevisePointType::class)->execute($this->admin, $type, [
                'category' => 'violation',
                'name' => 'Changed category',
                'points' => 15,
                'description' => 'Changed category description',
            ]);
            self::fail('Expected used category to be immutable.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('category', $exception->errors());
        }
        self::assertSame('achievement', $type->fresh()->category);
        self::assertSame(1, $type->pointTypeVersions()->count());
    }

    public function test_teacher_catalog_lists_active_types_with_the_latest_version_only(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);
        app(RevisePointType::class)->execute($this->admin, $type, [
            'name' => 'Latest achievement', 'points' => 15, 'description' => 'Latest description',
        ]);
        $inactive = PointType::create(['code' => 'A003', 'category' => 'achievement', 'active' => false]);
        PointTypeVersion::create(['point_type_id' => $inactive->id, 'version' => 1, 'name' => 'Inactive type', 'description' => 'Inactive description', 'points' => 1, 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/v1/teacher/point-types')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $type->id)
            ->assertJsonPath('data.0.version_id', $type->pointTypeVersions()->where('version', 2)->value('id'))
            ->assertJsonPath('data.0.category', 'achievement')
            ->assertJsonPath('data.0.name', 'Latest achievement')
            ->assertJsonPath('data.0.points', 15)
            ->assertJsonPath('data.0.description', 'Latest description')
            ->assertJsonMissingPath('data.0.version');
    }

    public function test_catalog_requires_an_active_activated_teacher_account(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/teacher/point-types')->assertForbidden();

        $pendingTeacher = User::create(['role' => 'teacher', 'name' => 'Pending teacher', 'password_hash' => 'Synthetic password phrase', 'account_status' => 'pending_activation', 'must_change_password' => true]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $pendingTeacher->id, 'teacher_code' => 'G002']);
        Sanctum::actingAs($pendingTeacher);
        $this->getJson('/api/v1/teacher/point-types')->assertForbidden();
    }

    public function test_deactivation_is_audited_and_hides_type_from_catalog(): void
    {
        $type = PointType::findOrFail($this->rows['point_types']['id']);
        app(DeactivatePointType::class)->execute($this->admin, $type);
        self::assertFalse($type->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $this->admin->id, 'resource_id' => $type->id, 'action' => 'point_type_deactivated']);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/v1/teacher/point-types')->assertOk()->assertJsonCount(0, 'data');
    }
}

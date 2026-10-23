<?php

namespace Tests\Feature;

use App\Models\PointRecord;
use App\Models\PointTypeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class DatabaseConstraintsTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->fixture();
    }

    private function rejects(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected PostgreSQL SQLSTATE $state");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    public static function uniqueCases(): array
    {
        return [
            'NISN keeps leading zeroes' => ['students', ['user_id']],
            'teacher code' => ['teachers', ['user_id', 'nip']],
            'teacher NIP' => ['teachers', ['user_id', 'teacher_code']],
            'login alias within role across identifier kinds' => ['login_identifiers', ['kind']],
            'academic year name' => ['academic_years', []],
            'semester name in year' => ['semesters', ['status']],
            'one active semester' => ['semesters', ['name']],
            'major code' => ['majors', []],
            'class code in year' => ['classrooms', []],
            'point type code' => ['point_types', []],
            'catalog version' => ['point_type_versions', []],
            'selection used once' => ['point_records', ['idempotency_key']],
            'teacher idempotency' => ['point_records', ['selection_id']],
            'token hash' => ['qr_tokens', []],
            'QR redeemed once' => ['qr_redemptions', ['request_key', 'selection_id']],
            'scan retry key per teacher' => ['qr_redemptions', ['qr_token_id', 'selection_id']],
            'revision number' => ['record_revisions', []],
            'one pending review' => ['review_requests', []],
            'threshold policy version' => ['threshold_policies', []],
            'threshold boundary' => ['threshold_levels', []],
            'one alert per level' => ['threshold_alerts', []],
            'notification event' => ['notifications', []],
            'outbox event' => ['outbox_events', []],
            'device token' => ['device_tokens', []],
            'school singleton' => ['school_settings', []],
        ];
    }

    #[DataProvider('uniqueCases')]
    public function test_duplicate_is_rejected_by_postgresql(string $table, array $different): void
    {
        $row = $this->rows[$table];
        $row['id'] = (string) Str::uuid();
        foreach ($different as $field) {
            if ($field === 'status') {
                $row[$field] = 'draft';
            } elseif ($field === 'kind') {
                $row[$field] = 'username';
            } elseif ($field === 'user_id') {
                $row[$field] = $this->row('users', ['role' => $table === 'students' ? 'student' : 'teacher', 'name' => 'Another synthetic user', 'password_hash' => 'synthetic-hash'])['id'];
            } elseif (in_array($field, ['selection_id', 'qr_token_id'])) {
                $target = $field === 'selection_id' ? 'student_selections' : 'qr_tokens';
                $copy = $this->rows[$target];
                unset($copy['id']);
                if ($target === 'qr_tokens') {
                    $copy['token_hash'] = str_repeat('d', 64);
                }
                $row[$field] = $this->row($target, $copy)['id'];
            } else {
                $row[$field] = 'different-value';
            }
        }
        $this->rejects('23505', fn () => DB::table($table)->insert($row));
    }

    public static function invalidCases(): array
    {
        return [
            ['point_type_versions', 'points', 0], ['point_type_versions', 'points', -1],
            ['record_revisions', 'points_snapshot', 0], ['threshold_levels', 'boundary_points', 0],
            ['users', 'role', 'unknown'], ['users', 'account_status', 'unknown'],
            ['students', 'education_status', 'unknown'], ['semesters', 'status', 'unknown'],
            ['point_types', 'category', 'unknown'], ['record_revisions', 'category_snapshot', 'unknown'],
            ['record_revisions', 'status', 'unknown'], ['review_requests', 'status', 'unknown'],
            ['review_requests', 'kind', 'unknown'], ['student_selections', 'source', 'unknown'],
            ['point_records', 'source', 'unknown'], ['follow_ups', 'status', 'unknown'],
            ['device_tokens', 'app', 'unknown'], ['login_identifiers', 'kind', 'unknown'],
            ['import_batches', 'status', 'unknown'], ['report_exports', 'status', 'unknown'],
            ['enrollments', 'valid_until', '2026-06-30 00:00:00+07'],
            ['attachments', 'size', 5242881], ['attachments', 'mime_type', 'text/html'],
            ['record_revisions', 'description', 'tiny'], ['review_requests', 'reason', str_repeat('x', 1001)],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_values_are_rejected_by_postgresql(string $table, string $field, mixed $value): void
    {
        $this->rejects('23514', fn () => DB::table($table)->where('id', $this->rows[$table]['id'])->update([$field => $value]));
    }

    public function test_overlapping_enrollment_is_rejected(): void
    {
        $row = $this->rows['enrollments'];
        $row['id'] = (string) Str::uuid();
        $this->rejects('23P01', fn () => DB::table('enrollments')->insert($row));
    }

    public function test_adjacent_enrollments_and_multiple_resolved_reviews_are_allowed(): void
    {
        $row = $this->rows['enrollments'];
        $row['id'] = (string) Str::uuid();
        $row['valid_from'] = $row['valid_until'];
        $row['valid_until'] = null;
        DB::table('enrollments')->insert($row);
        DB::table('review_requests')->update(['status' => 'approved']);
        $review = $this->rows['review_requests'];
        $review['id'] = (string) Str::uuid();
        DB::table('review_requests')->insert($review);
        $this->assertSame(2, DB::table('enrollments')->count());
        $this->assertSame(2, DB::table('review_requests')->count());
    }

    public function test_history_prevents_deletion_of_referenced_student(): void
    {
        $this->rejects('23001', fn () => DB::table('students')->where('id', $this->rows['students']['id'])->delete());
    }

    public function test_latest_revision_uses_revision_number_and_retains_old_evidence(): void
    {
        $this->assertTrue(class_exists(PointRecord::class), 'PointRecord model is missing');
        $row = $this->rows['record_revisions'];
        $row['id'] = (string) Str::uuid();
        $row['revision_number'] = 2;
        $row['points_snapshot'] = 20;
        $row['attachment_id'] = null;
        $row['created_at'] = '2026-07-31 09:00:00+07';
        DB::table('record_revisions')->insert($row);
        $record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $this->assertCount(2, $record->revisions);
        $this->assertSame(20, $record->latestRevision->points_snapshot);
        $this->assertSame($this->rows['attachments']['id'], $record->revisions->firstWhere('revision_number', 1)->attachment->id);
        $this->assertSame('0012345678', $record->student->nisn);
        $this->assertSame($record->semester->id, $record->enrollment->semester->id);
    }

    public function test_token_revocation_retains_qr_metadata_with_null_session(): void
    {
        $sessionId = $this->rows['qr_tokens']['session_id'];
        DB::table('personal_access_tokens')->where('id', $sessionId)->delete();
        $this->assertNull(DB::table('qr_tokens')->where('id', $this->rows['qr_tokens']['id'])->value('session_id'));
        $this->assertDatabaseHas('qr_tokens', ['id' => $this->rows['qr_tokens']['id']]);
    }

    public function test_user_auth_uses_hidden_password_hash_and_native_token_ownership(): void
    {
        $user = User::findOrFail($this->rows['students']['user_id']);
        $this->assertSame('synthetic-hash', $user->getAuthPassword());
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertSame($this->rows['qr_tokens']['session_id'], $user->tokens->sole()->id);
    }

    public function test_catalog_version_model_assigns_uuid_and_creation_time(): void
    {
        try {
            $version = PointTypeVersion::create([
                'point_type_id' => $this->rows['point_types']['id'], 'version' => 2,
                'name' => 'Synthetic second version', 'description' => 'Synthetic description',
                'points' => 15, 'created_by' => $this->rows['users']['id'],
            ]);
        } catch (QueryException $exception) {
            $this->fail('Catalog version model must assign its creation timestamp: '.$exception->errorInfo[0]);
        }
        $this->assertTrue(Str::isUuid($version->id));
        $this->assertNotNull($version->fresh()->created_at);
    }
}

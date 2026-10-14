<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_documented_domain_tables_exist_with_uuid_ids(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('civora_test', DB::connection()->getDatabaseName());
        foreach (['school_settings', 'users', 'login_identifiers', 'students', 'teachers', 'temporary_credentials', 'academic_years', 'semesters', 'majors', 'classrooms', 'enrollments', 'point_types', 'point_type_versions', 'student_selections', 'qr_tokens', 'qr_redemptions', 'point_records', 'record_revisions', 'review_requests', 'attachments', 'threshold_policies', 'threshold_levels', 'threshold_alerts', 'follow_ups', 'notifications', 'device_tokens', 'outbox_events', 'import_batches', 'report_exports', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing domain table: $table");
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'id');
            $this->assertSame('uuid', $column['type_name'], "$table ID must be UUID");
        }
    }

    public function test_health_returns_only_status(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }
}

<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait FoundationFixture
{
    private array $rows = [];

    private function row(string $table, array $values): array
    {
        $row = ['id' => (string) Str::uuid(), ...$values];
        DB::table($table)->insert($row);

        return $this->rows[$table] = $row;
    }

    private function fixture(): void
    {
        $now = '2026-08-01 09:00:00+07';
        $user = $this->row('users', ['role' => 'admin', 'name' => 'Synthetic Admin', 'password_hash' => 'synthetic-hash']);
        $studentUser = $this->row('users', ['role' => 'student', 'name' => 'Synthetic Student', 'password_hash' => 'synthetic-hash']);
        $teacherUser = $this->row('users', ['role' => 'teacher', 'name' => 'Synthetic Teacher', 'password_hash' => 'synthetic-hash']);
        $this->rows['users'] = $user;
        $student = $this->row('students', ['user_id' => $studentUser['id'], 'nisn' => '0012345678']);
        $teacher = $this->row('teachers', ['user_id' => $teacherUser['id'], 'nip' => '00098765', 'teacher_code' => 'G001']);
        $this->row('school_settings', ['name' => 'Synthetic School']);
        $this->row('login_identifiers', ['user_id' => $studentUser['id'], 'role' => 'student', 'kind' => 'nisn', 'normalized_value' => '0012345678']);
        $this->row('temporary_credentials', ['user_id' => $studentUser['id'], 'encrypted_secret' => 'encrypted-fixture', 'expires_at' => '2026-08-02 09:00:00+07', 'generated_by' => $user['id'], 'reason' => 'Initial account']);
        $year = $this->row('academic_years', ['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $semester = $this->row('semesters', ['academic_year_id' => $year['id'], 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $major = $this->row('majors', ['code' => 'RPL', 'name' => 'Synthetic Major']);
        $class = $this->row('classrooms', ['academic_year_id' => $year['id'], 'major_id' => $major['id'], 'code' => 'X-1', 'name' => 'Synthetic Class', 'grade' => 10]);
        $enrollment = $this->row('enrollments', ['student_id' => $student['id'], 'semester_id' => $semester['id'], 'classroom_id' => $class['id'], 'valid_from' => '2026-07-01 00:00:00+07', 'valid_until' => '2026-12-01 00:00:00+07']);
        $type = $this->row('point_types', ['code' => 'A001', 'category' => 'achievement']);
        $version = $this->row('point_type_versions', ['point_type_id' => $type['id'], 'version' => 1, 'name' => 'Synthetic Achievement', 'description' => 'Synthetic description', 'points' => 10, 'created_by' => $user['id'], 'created_at' => $now]);
        $selection = $this->row('student_selections', ['teacher_id' => $teacher['id'], 'student_id' => $student['id'], 'enrollment_id' => $enrollment['id'], 'semester_id' => $semester['id'], 'source' => 'qr', 'expires_at' => '2026-08-01 09:05:00+07']);
        $sessionId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => User::class, 'tokenable_id' => $studentUser['id'], 'name' => 'synthetic-device', 'token' => str_repeat('e', 64)]);
        $token = $this->row('qr_tokens', ['student_id' => $student['id'], 'session_id' => $sessionId, 'token_hash' => str_repeat('a', 64), 'issued_at' => $now, 'expires_at' => '2026-08-01 09:00:20+07']);
        $this->row('qr_redemptions', ['qr_token_id' => $token['id'], 'teacher_id' => $teacher['id'], 'request_key' => 'scan-1', 'selection_id' => $selection['id'], 'created_at' => $now]);
        $record = $this->row('point_records', ['student_id' => $student['id'], 'teacher_id' => $teacher['id'], 'enrollment_id' => $enrollment['id'], 'semester_id' => $semester['id'], 'selection_id' => $selection['id'], 'source' => 'qr', 'occurred_at' => $now, 'class_snapshot' => 'Synthetic Class', 'major_snapshot' => 'Synthetic Major', 'idempotency_key' => 'save-1', 'request_hash' => str_repeat('b', 64)]);
        $attachment = $this->row('attachments', ['owner_user_id' => $teacherUser['id'], 'private_path' => 'evidence/synthetic.png', 'mime_type' => 'image/png', 'size' => 1024, 'checksum' => str_repeat('c', 64), 'created_at' => $now]);
        $this->row('record_revisions', ['point_record_id' => $record['id'], 'revision_number' => 1, 'point_type_version_id' => $version['id'], 'points_snapshot' => 10, 'name_snapshot' => 'Synthetic Achievement', 'category_snapshot' => 'achievement', 'description' => 'Synthetic description', 'attachment_id' => $attachment['id'], 'status' => 'active', 'changed_by' => $teacherUser['id'], 'reason' => 'Initial record', 'created_at' => $now]);
        $this->row('review_requests', ['point_record_id' => $record['id'], 'requester_user_id' => $studentUser['id'], 'kind' => 'student_objection', 'reason' => 'Synthetic objection', 'status' => 'pending', 'reviewed_revision' => 1]);
        $policy = $this->row('threshold_policies', ['semester_id' => $semester['id'], 'version' => 1, 'created_by' => $user['id'], 'reason' => 'Synthetic policy', 'created_at' => $now]);
        $level = $this->row('threshold_levels', ['policy_id' => $policy['id'], 'boundary_points' => 25, 'label' => 'Synthetic Level', 'recommended_action' => 'Synthetic action']);
        $alert = $this->row('threshold_alerts', ['student_id' => $student['id'], 'semester_id' => $semester['id'], 'threshold_level_id' => $level['id'], 'reached_at' => $now, 'last_evaluated_at' => $now]);
        $this->row('follow_ups', ['threshold_alert_id' => $alert['id'], 'performed_by' => $user['id'], 'action' => 'Synthetic action', 'scheduled_or_performed_at' => $now]);
        $this->row('notifications', ['user_id' => $studentUser['id'], 'event_key' => 'record-1', 'type' => 'record_created', 'resource_id' => $record['id'], 'public_message' => 'Catatan tersedia.', 'created_at' => $now]);
        $this->row('device_tokens', ['user_id' => $studentUser['id'], 'app' => 'student', 'token' => 'synthetic-device-token', 'updated_at' => $now]);
        $this->row('outbox_events', ['event_key' => 'record-1', 'type' => 'record_created', 'resource_id' => $record['id'], 'payload' => '{}']);
        $this->row('import_batches', ['admin_id' => $user['id'], 'entity_type' => 'student', 'file_path' => 'imports/synthetic.csv']);
        $this->row('report_exports', ['admin_id' => $user['id'], 'filters' => '{}']);
        $this->row('audit_logs', ['actor_user_id' => $user['id'], 'action' => 'record_created', 'resource_type' => 'point_record', 'resource_id' => $record['id'], 'request_id' => (string) Str::uuid(), 'created_at' => $now]);
    }
}

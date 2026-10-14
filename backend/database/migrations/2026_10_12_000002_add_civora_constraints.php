<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::unprepared(<<<'SQL'
ALTER TABLE students ADD CONSTRAINT students_nisn_unique UNIQUE (nisn);
ALTER TABLE teachers ADD CONSTRAINT teachers_nip_unique UNIQUE (nip), ADD CONSTRAINT teachers_code_unique UNIQUE (teacher_code);
ALTER TABLE login_identifiers ADD CONSTRAINT login_identifiers_alias_unique UNIQUE (role, normalized_value);
ALTER TABLE academic_years ADD CONSTRAINT academic_years_name_unique UNIQUE (name);
ALTER TABLE semesters ADD CONSTRAINT semesters_name_unique UNIQUE (academic_year_id, name);
CREATE UNIQUE INDEX semesters_one_active ON semesters ((status)) WHERE status = 'active';
ALTER TABLE majors ADD CONSTRAINT majors_code_unique UNIQUE (code);
ALTER TABLE classrooms ADD CONSTRAINT classrooms_code_unique UNIQUE (academic_year_id, code);
ALTER TABLE point_types ADD CONSTRAINT point_types_code_unique UNIQUE (code);
ALTER TABLE point_type_versions ADD CONSTRAINT point_type_versions_version_unique UNIQUE (point_type_id, version);
ALTER TABLE point_records ADD CONSTRAINT point_records_selection_unique UNIQUE (selection_id), ADD CONSTRAINT point_records_idempotency_unique UNIQUE (teacher_id, idempotency_key);
ALTER TABLE qr_tokens ADD CONSTRAINT qr_tokens_hash_unique UNIQUE (token_hash);
ALTER TABLE qr_tokens ADD CONSTRAINT qr_tokens_session_foreign FOREIGN KEY (session_id) REFERENCES personal_access_tokens(id) ON DELETE SET NULL;
ALTER TABLE qr_redemptions ADD CONSTRAINT qr_redemptions_token_unique UNIQUE (qr_token_id), ADD CONSTRAINT qr_redemptions_selection_unique UNIQUE (selection_id), ADD CONSTRAINT qr_redemptions_request_unique UNIQUE (teacher_id, request_key);
ALTER TABLE record_revisions ADD CONSTRAINT record_revisions_number_unique UNIQUE (point_record_id, revision_number);
CREATE UNIQUE INDEX review_requests_one_pending ON review_requests (point_record_id) WHERE status = 'pending';
ALTER TABLE threshold_policies ADD CONSTRAINT threshold_policies_version_unique UNIQUE (semester_id, version);
ALTER TABLE threshold_levels ADD CONSTRAINT threshold_levels_boundary_unique UNIQUE (policy_id, boundary_points);
ALTER TABLE threshold_alerts ADD CONSTRAINT threshold_alerts_level_unique UNIQUE (student_id, semester_id, threshold_level_id);
ALTER TABLE notifications ADD CONSTRAINT notifications_event_unique UNIQUE (user_id, event_key);
ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_event_unique UNIQUE (event_key);
ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_token_unique UNIQUE (token);
CREATE UNIQUE INDEX school_settings_singleton ON school_settings ((true));
ALTER TABLE enrollments ADD CONSTRAINT enrollments_no_overlap EXCLUDE USING gist (student_id WITH =, semester_id WITH =, tstzrange(valid_from, valid_until, '[)') WITH &&);
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('student', 'teacher', 'admin')), ADD CONSTRAINT users_status_check CHECK (account_status IN ('pending_activation', 'active', 'inactive'));
ALTER TABLE login_identifiers ADD CONSTRAINT login_identifiers_role_check CHECK (role IN ('student', 'teacher', 'admin')), ADD CONSTRAINT login_identifiers_kind_check CHECK (kind IN ('nisn', 'nip', 'teacher_code', 'username'));
ALTER TABLE students ADD CONSTRAINT students_status_check CHECK (education_status IN ('active', 'transferred', 'graduated'));
ALTER TABLE academic_years ADD CONSTRAINT academic_years_dates_check CHECK (start_date <= end_date);
ALTER TABLE semesters ADD CONSTRAINT semesters_status_check CHECK (status IN ('draft', 'active', 'closed')), ADD CONSTRAINT semesters_dates_check CHECK (start_date <= end_date);
ALTER TABLE enrollments ADD CONSTRAINT enrollments_dates_check CHECK (valid_until IS NULL OR valid_from < valid_until);
ALTER TABLE point_types ADD CONSTRAINT point_types_category_check CHECK (category IN ('achievement', 'violation'));
ALTER TABLE point_type_versions ADD CONSTRAINT point_type_versions_points_check CHECK (points > 0), ADD CONSTRAINT point_type_versions_version_check CHECK (version > 0);
ALTER TABLE student_selections ADD CONSTRAINT student_selections_source_check CHECK (source IN ('qr', 'manual'));
ALTER TABLE point_records ADD CONSTRAINT point_records_source_check CHECK (source IN ('qr', 'manual'));
ALTER TABLE record_revisions ADD CONSTRAINT record_revisions_points_check CHECK (points_snapshot > 0), ADD CONSTRAINT record_revisions_number_check CHECK (revision_number > 0), ADD CONSTRAINT record_revisions_status_check CHECK (status IN ('active', 'cancelled')), ADD CONSTRAINT record_revisions_category_check CHECK (category_snapshot IN ('achievement', 'violation')), ADD CONSTRAINT record_revisions_description_check CHECK (char_length(description) BETWEEN 5 AND 1000), ADD CONSTRAINT record_revisions_reason_check CHECK (char_length(reason) BETWEEN 5 AND 1000);
ALTER TABLE review_requests ADD CONSTRAINT review_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected')), ADD CONSTRAINT review_requests_kind_check CHECK (kind IN ('student_objection', 'teacher_correction')), ADD CONSTRAINT review_requests_reason_check CHECK (char_length(reason) BETWEEN 5 AND 1000), ADD CONSTRAINT review_requests_revision_check CHECK (reviewed_revision > 0);
ALTER TABLE threshold_policies ADD CONSTRAINT threshold_policies_version_check CHECK (version > 0);
ALTER TABLE threshold_levels ADD CONSTRAINT threshold_levels_points_check CHECK (boundary_points > 0);
ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_status_check CHECK (status IN ('planned', 'completed', 'cancelled'));
ALTER TABLE attachments ADD CONSTRAINT attachments_size_check CHECK (size BETWEEN 1 AND 5242880), ADD CONSTRAINT attachments_mime_check CHECK (mime_type IN ('image/jpeg', 'image/png'));
ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_app_check CHECK (app IN ('student', 'teacher'));
ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_attempts_check CHECK (attempts >= 0);
ALTER TABLE import_batches ADD CONSTRAINT import_batches_status_check CHECK (status IN ('preview', 'confirmed', 'failed', 'cancelled')), ADD CONSTRAINT import_batches_count_check CHECK (row_count BETWEEN 0 AND 5000), ADD CONSTRAINT import_batches_entity_check CHECK (entity_type IN ('student', 'teacher'));
ALTER TABLE report_exports ALTER COLUMN status SET DEFAULT 'queued';
ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed'));
CREATE INDEX point_records_student_history ON point_records (student_id, semester_id, occurred_at, id);
CREATE INDEX point_records_teacher_history ON point_records (teacher_id, occurred_at, id);
CREATE INDEX point_records_class_history ON point_records (enrollment_id, occurred_at, id);
CREATE INDEX record_revisions_latest ON record_revisions (point_record_id, revision_number DESC);
CREATE INDEX review_requests_queue ON review_requests (status, created_at, id);
CREATE INDEX notifications_unread ON notifications (user_id, read_at, created_at, id);
CREATE INDEX qr_tokens_expiration ON qr_tokens (expires_at);
CREATE INDEX student_selections_expiration ON student_selections (teacher_id, expires_at);
CREATE INDEX enrollments_student_period ON enrollments (student_id, semester_id, valid_from);
CREATE INDEX enrollments_class_semester ON enrollments (classroom_id, semester_id);
SQL);
    }

    public function down(): void
    {
        // Remove only this migration's indexes and constraints; tables remain usable for rollback.
        $indexes = ['semesters_one_active', 'review_requests_one_pending', 'school_settings_singleton', 'point_records_student_history', 'point_records_teacher_history', 'point_records_class_history', 'record_revisions_latest', 'review_requests_queue', 'notifications_unread', 'qr_tokens_expiration', 'student_selections_expiration', 'enrollments_student_period', 'enrollments_class_semester'];
        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS $index");
        }
        $constraints = DB::select("SELECT conrelid::regclass::text AS table_name, conname FROM pg_constraint WHERE connamespace = 'public'::regnamespace AND conname IN ('students_nisn_unique', 'teachers_nip_unique', 'teachers_code_unique', 'login_identifiers_alias_unique', 'academic_years_name_unique', 'semesters_name_unique', 'majors_code_unique', 'classrooms_code_unique', 'point_types_code_unique', 'point_type_versions_version_unique', 'point_records_selection_unique', 'point_records_idempotency_unique', 'qr_tokens_hash_unique', 'qr_redemptions_token_unique', 'qr_redemptions_selection_unique', 'qr_redemptions_request_unique', 'record_revisions_number_unique', 'threshold_policies_version_unique', 'threshold_levels_boundary_unique', 'threshold_alerts_level_unique', 'notifications_event_unique', 'outbox_events_event_unique', 'device_tokens_token_unique', 'enrollments_no_overlap', 'users_role_check', 'users_status_check', 'login_identifiers_role_check', 'login_identifiers_kind_check', 'students_status_check', 'academic_years_dates_check', 'semesters_status_check', 'semesters_dates_check', 'enrollments_dates_check', 'point_types_category_check', 'point_type_versions_points_check', 'point_type_versions_version_check', 'student_selections_source_check', 'point_records_source_check', 'record_revisions_points_check', 'record_revisions_number_check', 'record_revisions_status_check', 'record_revisions_category_check', 'record_revisions_description_check', 'record_revisions_reason_check', 'review_requests_status_check', 'review_requests_kind_check', 'review_requests_reason_check', 'review_requests_revision_check', 'threshold_policies_version_check', 'threshold_levels_points_check', 'follow_ups_status_check', 'attachments_size_check', 'attachments_mime_check', 'device_tokens_app_check', 'outbox_events_attempts_check', 'import_batches_status_check', 'import_batches_count_check', 'import_batches_entity_check', 'report_exports_status_check')");
        foreach ($constraints as $constraint) {
            DB::statement('ALTER TABLE "'.$constraint->table_name.'" DROP CONSTRAINT "'.$constraint->conname.'"');
        }
        DB::statement("ALTER TABLE report_exports ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement('ALTER TABLE qr_tokens DROP CONSTRAINT qr_tokens_session_foreign');
        // btree_gist may be shared by other applications and is deliberately retained.
    }
};

<?php

namespace App\Console\Commands;

use App\Models\LoginIdentifier;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PrepareMobileQaFixtures extends Command
{
    protected $signature = 'civora:prepare-mobile-qa-fixtures';

    protected $description = 'Prepare disposable synthetic mobile QA accounts in the owned empty civora_test database';

    public function handle(): int
    {
        try {
            $connection = $this->assertOwnedTestDatabase();
            $directory = base_path('../docs/qa');
            if (file_exists($directory.'/mobile.student.json') || file_exists($directory.'/mobile.teacher.json')) {
                throw new RuntimeException('Remove existing private QA config files before preparing new fixtures.');
            }
            $tables = $connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'");
            foreach ($tables as $tableRow) {
                $table = $tableRow->tablename;
                if ($connection->table($table)->exists()) {
                    throw new RuntimeException('The disposable test database must be empty.');
                }
            }

            $studentPassword = $this->randomPassword();
            $teacherPassword = $this->randomPassword();
            DB::connection($connection->getName())->transaction(function () use ($studentPassword, $teacherPassword): void {
                $now = now();
                $admin = User::create([
                    'role' => 'admin', 'name' => 'M06 QA Fixture Owner',
                    'password_hash' => $this->randomPassword(), 'account_status' => 'active', 'must_change_password' => false,
                ]);
                $studentUser = User::create([
                    'role' => 'student', 'name' => 'M06 QA Student',
                    'password_hash' => $studentPassword, 'account_status' => 'active', 'must_change_password' => false,
                ]);
                $teacherUser = User::create([
                    'role' => 'teacher', 'name' => 'M06 QA Teacher',
                    'password_hash' => $teacherPassword, 'account_status' => 'active', 'must_change_password' => false,
                ]);

                $student = Student::create(['user_id' => $studentUser->id, 'nisn' => '9000000001', 'education_status' => 'active']);
                $teacher = Teacher::create(['user_id' => $teacherUser->id, 'nip' => '9000000002', 'teacher_code' => 'M06QA']);
                LoginIdentifier::create(['user_id' => $studentUser->id, 'role' => 'student', 'kind' => 'nisn', 'normalized_value' => '9000000001']);
                LoginIdentifier::create(['user_id' => $teacherUser->id, 'role' => 'teacher', 'kind' => 'nip', 'normalized_value' => '9000000002']);
                LoginIdentifier::create(['user_id' => $teacherUser->id, 'role' => 'teacher', 'kind' => 'teacher_code', 'normalized_value' => 'm06qa']);

                DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'name' => 'M06 QA Synthetic School', 'timezone' => 'Asia/Jakarta', 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                $yearId = (string) Str::uuid();
                DB::table('academic_years')->insert(['id' => $yearId, 'name' => 'M06 QA 2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'created_at' => $now, 'updated_at' => $now]);
                $semesterId = (string) Str::uuid();
                DB::table('semesters')->insert(['id' => $semesterId, 'academic_year_id' => $yearId, 'name' => 'M06 QA Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                $majorId = (string) Str::uuid();
                DB::table('majors')->insert(['id' => $majorId, 'code' => 'M06QA', 'name' => 'M06 QA Major', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                $classId = (string) Str::uuid();
                DB::table('classrooms')->insert(['id' => $classId, 'academic_year_id' => $yearId, 'major_id' => $majorId, 'code' => 'M06QA', 'name' => 'M06 QA Class', 'grade' => 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                $enrollmentId = (string) Str::uuid();
                DB::table('enrollments')->insert(['id' => $enrollmentId, 'student_id' => $student->id, 'semester_id' => $semesterId, 'classroom_id' => $classId, 'valid_from' => '2026-07-01 00:00:00+07', 'valid_until' => '2027-07-01 00:00:00+07', 'created_at' => $now, 'updated_at' => $now]);

                $typeId = (string) Str::uuid();
                $versionId = (string) Str::uuid();
                DB::table('point_types')->insert(['id' => $typeId, 'code' => 'M06QA', 'category' => 'achievement', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('point_type_versions')->insert(['id' => $versionId, 'point_type_id' => $typeId, 'version' => 1, 'name' => 'M06 QA Achievement', 'description' => 'Synthetic mobile integration point type', 'points' => 10, 'created_by' => $admin->id, 'created_at' => $now]);

                $selectionId = (string) Str::uuid();
                DB::table('student_selections')->insert(['id' => $selectionId, 'teacher_id' => $teacher->id, 'student_id' => $student->id, 'enrollment_id' => $enrollmentId, 'semester_id' => $semesterId, 'source' => 'manual', 'expires_at' => $now->copy()->addMinutes(5), 'created_at' => $now]);
                $recordId = (string) Str::uuid();
                DB::table('point_records')->insert(['id' => $recordId, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollment_id' => $enrollmentId, 'semester_id' => $semesterId, 'selection_id' => $selectionId, 'source' => 'manual', 'occurred_at' => $now, 'class_snapshot' => 'M06 QA Class', 'major_snapshot' => 'M06 QA Major', 'idempotency_key' => 'm06-fixture-record', 'request_hash' => hash('sha256', 'm06-fixture-record'), 'created_at' => $now]);
                DB::table('record_revisions')->insert(['id' => (string) Str::uuid(), 'point_record_id' => $recordId, 'revision_number' => 1, 'point_type_version_id' => $versionId, 'points_snapshot' => 10, 'name_snapshot' => 'M06 QA Seeded Achievement', 'category_snapshot' => 'achievement', 'description' => 'Synthetic seeded record for mobile QA.', 'status' => 'active', 'changed_by' => $teacherUser->id, 'reason' => 'Synthetic mobile QA fixture.', 'created_at' => $now]);

            });

            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Could not prepare the ignored QA config directory.');
            }
            $baseUrl = 'http://10.0.2.2:8031/api/v1';
            $this->writeConfig($directory.'/mobile.student.json', [
                'CIVORA_API_BASE_URL' => $baseUrl,
                'CIVORA_QA_STUDENT_NISN' => '9000000001',
                'CIVORA_QA_STUDENT_PASSWORD' => $studentPassword,
                'CIVORA_QA_STUDENT_RECORD_NAME' => 'M06 QA Seeded Achievement',
            ]);
            $this->writeConfig($directory.'/mobile.teacher.json', [
                'CIVORA_API_BASE_URL' => $baseUrl,
                'CIVORA_QA_TEACHER_IDENTIFIER' => '9000000002',
                'CIVORA_QA_TEACHER_PASSWORD' => $teacherPassword,
                'CIVORA_QA_STUDENT_NAME' => 'M06 QA Student',
                'CIVORA_QA_STUDENT_NISN' => '9000000001',
            ]);

            $this->info('Synthetic mobile QA fixtures created in owned civora_test. Credentials were written only to ignored docs/qa JSON files.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Fixtures were not created. Confirm this is the owned, empty civora_test database and check local runtime setup.');

            return self::FAILURE;
        }
    }

    private function assertOwnedTestDatabase(): Connection
    {
        if (app()->environment() !== 'testing') {
            throw new RuntimeException('Testing environment required.');
        }
        $markerPath = base_path('../.runtime/civora-cluster.json');
        $dataPath = realpath(base_path('../.runtime/pgsql'));
        if (! is_file($markerPath) || ! $dataPath) {
            throw new RuntimeException('Project runtime marker not found.');
        }
        $marker = json_decode((string) file_get_contents($markerPath), true, flags: JSON_THROW_ON_ERROR);
        $name = DB::getDefaultConnection();
        $config = config("database.connections.$name");
        if (str_contains($name, '::') || ! is_array($config) || ! empty($config['url'])
            || ! empty($config['read']) || ! empty($config['write']) || ! empty($config['direct']) || ! empty($config['pooled'])
            || ($config['driver'] ?? null) !== 'pgsql' || ($config['database'] ?? null) !== 'civora_test'
            || ($config['host'] ?? null) !== '127.0.0.1' || (string) ($config['port'] ?? '') !== (string) ($marker['port'] ?? '')
            || ($config['schema'] ?? 'public') !== 'public' || realpath((string) ($marker['data'] ?? '')) !== $dataPath) {
            throw new RuntimeException('Database connection does not match the project test marker.');
        }
        $connection = DB::connection($name);
        if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'civora_test') {
            throw new RuntimeException('Unexpected database identity.');
        }
        $identity = $connection->selectFromWriteConnection("SELECT current_database() AS database, current_setting('data_directory') AS data_directory")[0];
        if ($identity->database !== 'civora_test' || realpath($identity->data_directory) !== $dataPath) {
            throw new RuntimeException('Database server data directory does not match the project marker.');
        }

        return $connection;
    }

    private function randomPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function writeConfig(string $path, array $config): void
    {
        if (file_exists($path)) {
            throw new RuntimeException('A private mobile QA credential file already exists.');
        }
        $written = file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Could not write a private mobile QA config file.');
        }
        @chmod($path, 0600);
    }
}

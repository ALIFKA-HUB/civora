<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class RecordPointConcurrencyTest extends TestCase
{
    use DatabaseMigrations, FoundationFixture;

    public function test_parallel_records_cross_a_combined_threshold_without_losing_either_write(): void
    {
        $this->fixture();
        $this->prepareClockAndTeacher();
        $selectionOne = $this->selection();
        $selectionTwo = $this->selection();
        $version = $this->createViolationType(13);
        DB::table('threshold_alerts')->where('id', $this->rows['threshold_alerts']['id'])->update(['currently_reached' => false]);
        [$first, $second, $directory] = $this->launchPair([
            ['record', $this->teacherId(), $selectionOne, $version, 'parallel-record-a'],
            ['record', $this->teacherId(), $selectionTwo, $version, 'parallel-record-b'],
        ]);
        try {
            $this->releaseAndWait($first, $second, $directory);
            self::assertEqualsCanonicalizing(['recorded', 'recorded'], [$first->getOutput(), $second->getOutput()]);
            self::assertSame(2, DB::table('point_records')->whereIn('selection_id', [$selectionOne, $selectionTwo])->count());
            self::assertSame(26, DB::table('record_revisions')->join('point_records', 'point_records.id', '=', 'record_revisions.point_record_id')->whereIn('point_records.selection_id', [$selectionOne, $selectionTwo])->sum('points_snapshot'));
            self::assertTrue((bool) DB::table('threshold_alerts')->where('id', $this->rows['threshold_alerts']['id'])->value('currently_reached'));
        } finally {
            $this->cleanupPair($first, $second, $directory);
        }
    }

    public function test_move_and_record_race_never_attaches_a_record_to_the_wrong_enrollment(): void
    {
        $this->fixture();
        $this->prepareClockAndTeacher();
        $selection = $this->selection();
        $version = $this->rows['point_type_versions']['id'];
        $classroom = [...$this->rows['classrooms'], 'id' => (string) Str::uuid(), 'code' => 'X-2', 'name' => 'Concurrent destination'];
        DB::table('classrooms')->insert($classroom);
        $admin = User::findOrFail($this->rows['users']['id']);
        $admin->update(['account_status' => 'active', 'must_change_password' => false]);
        [$first, $second, $directory] = $this->launchPair([
            ['record', $this->teacherId(), $selection, $version, 'move-race-record'],
            ['move', $admin->id, $classroom['id'], '2026-08-01 10:00:00+07'],
        ]);
        try {
            $this->releaseAndWait($first, $second, $directory);
            $results = [$first->getOutput(), $second->getOutput()];
            self::assertSame(1, count(array_intersect($results, ['recorded', 'moved'])));
            self::assertSame(1, count(array_intersect($results, ['conflict'])));
            self::assertSame(0, DB::table('point_records')->where('idempotency_key', 'move-race-record')->where('enrollment_id', '!=', $this->rows['enrollments']['id'])->count());
            $recorded = DB::table('point_records')->where('idempotency_key', 'move-race-record')->first();
            if ($recorded) {
                self::assertSame($this->rows['enrollments']['id'], $recorded->enrollment_id);
            } else {
                self::assertSame(0, DB::table('point_records')->where('selection_id', $selection)->count());
            }
        } finally {
            $this->cleanupPair($first, $second, $directory);
        }
    }

    private function prepareClockAndTeacher(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00+07');
        User::whereKey(DB::table('teachers')->value('user_id'))->update(['account_status' => 'active', 'must_change_password' => false]);
    }

    private function teacherId(): string
    {
        return (string) DB::table('teachers')->value('user_id');
    }

    private function selection(): string
    {
        $id = (string) Str::uuid();
        DB::table('student_selections')->insert([
            'id' => $id,
            'teacher_id' => DB::table('teachers')->value('id'),
            'student_id' => $this->rows['students']['id'],
            'enrollment_id' => $this->rows['enrollments']['id'],
            'semester_id' => $this->rows['semesters']['id'],
            'source' => 'manual',
            'expires_at' => CarbonImmutable::now()->addMinutes(5),
        ]);

        return $id;
    }

    private function createViolationType(int $points): string
    {
        $type = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('point_types')->insert(['id' => $type, 'code' => 'V001', 'category' => 'violation', 'active' => true]);
        DB::table('point_type_versions')->insert(['id' => $version, 'point_type_id' => $type, 'version' => 1, 'name' => 'Synthetic violation', 'description' => 'Concurrent threshold test', 'points' => $points, 'created_by' => $this->rows['users']['id'], 'created_at' => CarbonImmutable::now()]);

        return $version;
    }

    private function launchPair(array $commands): array
    {
        $directory = base_path('../.runtime/record-tests/'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $configFile = $directory.'/config.php';
        file_put_contents($configFile, '<?php return '.var_export(config()->all(), true).';');
        $common = ['APP_CONFIG_CACHE' => $configFile, 'CIVORA_TEST_RELEASE_FILE' => $directory.'/release', 'CIVORA_TEST_NOW' => CarbonImmutable::now()->toIso8601String()];
        $first = new Process([PHP_BINARY, base_path('tests/Support/record-concurrency-probe.php'), ...$commands[0]], base_path(), $common);
        $second = new Process([PHP_BINARY, base_path('tests/Support/record-concurrency-probe.php'), ...$commands[1]], base_path(), $common);
        $first->setTimeout(20);
        $second->setTimeout(20);
        $first->start();
        $second->start();

        return [$first, $second, $directory];
    }

    private function releaseAndWait(Process $first, Process $second, string $directory): void
    {
        file_put_contents($directory.'/release', 'go');
        $first->wait();
        $second->wait();
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
    }

    private function cleanupPair(Process $first, Process $second, string $directory): void
    {
        $first->stop();
        $second->stop();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Reviews\SubmitReviewRequest;
use App\Models\PointRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class ReviewDecisionConcurrencyTest extends TestCase
{
    use DatabaseMigrations, FoundationFixture;

    public function test_parallel_admin_decisions_with_same_revision_commit_once_and_return_409_to_loser(): void
    {
        $this->fixture();
        CarbonImmutable::setTestNow('2026-08-01 10:00:00+07');
        DB::table('review_requests')->delete();
        $admin = User::findOrFail($this->rows['users']['id']);
        $admin->update(['account_status' => 'active', 'must_change_password' => false]);
        $secondAdmin = User::create(['role' => 'admin', 'name' => 'Concurrent Admin', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        $student = User::findOrFail(DB::table('students')->value('user_id'));
        $student->update(['account_status' => 'active', 'must_change_password' => false]);
        $review = app(SubmitReviewRequest::class)->execute($student, PointRecord::findOrFail($this->rows['point_records']['id']), 'student_objection', 'Periksa catatan sintetis.', []);

        [$first, $second, $directory] = $this->launchPair([[$admin->id, $review->id], [$secondAdmin->id, $review->id]]);
        try {
            $this->releaseAndWait($first, $second, $directory);
            self::assertEqualsCanonicalizing(['approved', 'conflict-409'], [$first->getOutput(), $second->getOutput()]);
            self::assertSame(2, DB::table('record_revisions')->where('point_record_id', $this->rows['point_records']['id'])->count());
            self::assertSame('approved', DB::table('review_requests')->where('id', $review->id)->value('status'));
            self::assertSame(1, DB::table('audit_logs')->where('action', 'review_approve')->where('resource_id', $review->id)->count());
            self::assertSame(1, DB::table('outbox_events')->where('event_key', 'review-decision:'.$review->id)->count());
        } finally {
            $this->cleanupPair($first, $second, $directory);
        }
    }

    private function launchPair(array $actors): array
    {
        $directory = base_path('../.runtime/review-tests/'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $configFile = $directory.'/config.php';
        file_put_contents($configFile, '<?php return '.var_export(config()->all(), true).';');
        $common = ['APP_CONFIG_CACHE' => $configFile, 'CIVORA_TEST_RELEASE_FILE' => $directory.'/release', 'CIVORA_TEST_NOW' => CarbonImmutable::now()->toIso8601String()];
        $first = new Process([PHP_BINARY, base_path('tests/Support/review-decision-concurrency-probe.php'), ...$actors[0]], base_path(), [...$common, 'CIVORA_TEST_READY_FILE' => $directory.'/ready-1']);
        $second = new Process([PHP_BINARY, base_path('tests/Support/review-decision-concurrency-probe.php'), ...$actors[1]], base_path(), [...$common, 'CIVORA_TEST_READY_FILE' => $directory.'/ready-2']);
        $first->setTimeout(20);
        $second->setTimeout(20);
        $first->start();
        $second->start();

        return [$first, $second, $directory];
    }

    private function releaseAndWait(Process $first, Process $second, string $directory): void
    {
        $this->waitUntilReady($first, $second, $directory);
        self::assertTrue($first->isRunning(), 'First decision process exited before barrier release. '.$first->getErrorOutput());
        self::assertTrue($second->isRunning(), 'Second decision process exited before barrier release. '.$second->getErrorOutput());
        file_put_contents($directory.'/release', 'go');
        $first->wait();
        $second->wait();
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
    }

    private function waitUntilReady(Process $first, Process $second, string $directory): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
            foreach ([$first, $second] as $index => $process) {
                if (! $process->isRunning()) {
                    self::fail('Decision process '.($index + 1).' exited before reaching the shared barrier. Exit '.$process->getExitCode().'; stderr: '.$process->getErrorOutput().'; stdout: '.$process->getOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                self::fail('Timed out waiting for both decision processes to reach the shared barrier.');
            }
            usleep(10000);
        }
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

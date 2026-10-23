<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function mutations(): array
    {
        return [['deactivate', 'deactivate'], ['reset', 'deactivate'], ['deactivate', 'reset']];
    }

    #[DataProvider('mutations')]
    public function test_concurrent_mutations_cannot_remove_last_active_admin(string $firstAction, string $secondAction): void
    {
        $admins = [];
        for ($i = 0; $i < 2; $i++) {
            $admins[] = User::create(['role' => 'admin', 'name' => 'Synthetic concurrent admin', 'password_hash' => 'Synthetic concurrency phrase', 'account_status' => 'active', 'must_change_password' => false]);
        }
        $directory = base_path('../.runtime/account-tests/'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $configFile = $directory.'/config.php';
        file_put_contents($configFile, '<?php return '.var_export(config()->all(), true).';');
        $first = new Process([PHP_BINARY, base_path('tests/Support/account-concurrency-probe.php'), $admins[0]->id, $firstAction, 'civora-admin-first', $directory], base_path(), ['APP_CONFIG_CACHE' => $configFile]);
        $second = new Process([PHP_BINARY, base_path('tests/Support/account-concurrency-probe.php'), $admins[1]->id, $secondAction, 'civora-admin-second'], base_path(), ['APP_CONFIG_CACHE' => $configFile]);
        $first->setTimeout(20);
        $second->setTimeout(20);
        try {
            $first->start();
            $this->waitUntil(fn () => file_exists($directory.'/locked'));
            $second->start();
            $this->waitUntil(fn () => DB::table('pg_stat_activity')->where('application_name', 'civora-admin-second')->where('wait_event', 'advisory')->exists());
            file_put_contents($directory.'/release', 'go');
            $first->wait();
            $second->wait();
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            self::assertSame('completed', $first->getOutput());
            self::assertSame('protected', $second->getOutput());
            self::assertSame(1, User::where('role', 'admin')->where('account_status', 'active')->where('must_change_password', false)->count());
        } finally {
            $first->stop();
            $second->stop();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function waitUntil(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                self::fail('Concurrent action did not reach its PostgreSQL lock barrier.');
            }
            usleep(10000);
        }
    }
}

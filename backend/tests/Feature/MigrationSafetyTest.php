<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MigrationSafetyTest extends TestCase
{
    private function probe(string $command, array $overrides, array $options = []): array
    {
        $config = $this->app['config']->all();
        foreach ($overrides as $key => $value) {
            data_set($config, $key, $value);
        }
        $directory = base_path('../.runtime/guard-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $configPath = tempnam($directory, 'config-');
        $sentinel = tempnam($directory, 'sentinel-');
        unlink($sentinel);
        file_put_contents($configPath, '<?php return '.var_export($config, true).';');
        try {
            $process = new Process([PHP_BINARY, base_path('tests/Support/cli-migration-probe.php'), $command, '--env=testing', '--force', ...$options], base_path(), [
                'APP_CONFIG_CACHE' => '../.runtime/guard-tests/'.basename($configPath),
                'CIVORA_GUARD_PROBE_OUTPUT' => $sentinel,
            ]);
            $process->run();

            return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput(), file_exists($sentinel)];
        } finally {
            unlink($configPath);
            if (file_exists($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    public static function destructiveCommands(): array
    {
        return array_map(fn ($command) => [$command], ['migrate:fresh', 'migrate:rollback', 'migrate:refresh', 'migrate:reset', 'db:wipe']);
    }

    #[DataProvider('destructiveCommands')]
    public function test_cached_development_connection_is_refused_before_command_execution(string $command): void
    {
        [$exit, $output, $executed] = $this->probe($command, ['database.connections.pgsql.database' => 'civora_dev']);
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Destructive database commands require the owned civora_test PostgreSQL database.', $output);
        $this->assertFalse($executed);
    }

    public static function unsafeConnections(): array
    {
        return [
            'URL overrides database' => [['database.connections.pgsql.url' => 'postgresql://127.0.0.1:54329/civora_dev']],
            'URL overrides host' => [['database.connections.pgsql.url' => 'postgresql://192.0.2.1:54329/civora_test']],
            'external host' => [['database.connections.pgsql.host' => '192.0.2.1']],
            'wrong port' => [['database.connections.pgsql.port' => 5432]],
            'other driver' => [['database.connections.pgsql.driver' => 'sqlite', 'database.connections.pgsql.database' => ':memory:']],
            'typed default connection' => [['database.default' => 'pgsql::read']],
        ];
    }

    #[DataProvider('unsafeConnections')]
    public function test_resolved_unsafe_connection_is_refused(array $overrides): void
    {
        [$exit, $output, $executed] = $this->probe('migrate:fresh', $overrides);
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Destructive database commands require the owned civora_test PostgreSQL database.', $output);
        $this->assertFalse($executed);
    }

    public function test_explicit_database_connection_option_cannot_bypass_the_guard(): void
    {
        [$exit, $output, $executed] = $this->probe('migrate:rollback', ['database.connections.unsafe' => [...config('database.connections.pgsql'), 'database' => 'civora_dev']], ['--database=unsafe']);
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Destructive database commands require the owned civora_test PostgreSQL database.', $output);
        $this->assertFalse($executed);
    }

    public function test_owned_disposable_connection_is_accepted(): void
    {
        [$exit, $output, $executed] = $this->probe('migrate:fresh', []);
        $this->assertSame(0, $exit, $output);
        $this->assertTrue($executed);
    }

    public function test_typed_connection_option_is_refused_before_resolving_a_pdo(): void
    {
        [$exit, $output, $executed] = $this->probe('migrate:fresh', [], ['--database=pgsql::read']);
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Destructive database commands require the owned civora_test PostgreSQL database.', $output);
        $this->assertFalse($executed);
    }

    public static function alternateEndpoints(): array
    {
        $directUrl = 'postgresql://127.0.0.1:54329/civora_test?direct%5Bdatabase%5D=civora_dev';

        return [
            'cached direct with default name' => [['database.connections.pgsql.direct' => ['database' => 'civora_dev']], []],
            'cached direct with explicit ordinary name' => [['database.connections.pgsql.direct' => ['database' => 'civora_dev']], ['--database=pgsql']],
            'cached URL direct with default name' => [['database.connections.pgsql.url' => $directUrl], []],
            'cached URL direct with explicit ordinary name' => [['database.connections.pgsql.url' => $directUrl], ['--database=pgsql']],
            'cached pooled flag' => [['database.connections.pgsql.pooled' => true], []],
            'cached URL pooled flag' => [['database.connections.pgsql.url' => 'postgresql://127.0.0.1:54329/civora_test?pooled=true'], []],
        ];
    }

    #[DataProvider('alternateEndpoints')]
    public function test_direct_and_pooled_endpoints_are_refused_before_handler_execution(array $overrides, array $options): void
    {
        // The real db:wipe handler is replaced by the probe; no direct PDO or schema is touched.
        [$exit, $output, $executed] = $this->probe('db:wipe', $overrides, $options);
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Destructive database commands require the owned civora_test PostgreSQL database.', $output);
        $this->assertFalse($executed);
    }
}

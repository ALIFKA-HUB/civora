<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GuardDestructiveMigrations
{
    private const MESSAGE = 'Destructive database commands require the owned civora_test PostgreSQL database.';

    public function __invoke(CommandStarting $event): void
    {
        if (! in_array($event->command, ['migrate:fresh', 'migrate:rollback', 'migrate:refresh', 'migrate:reset', 'db:wipe'], true)) {
            return;
        }

        try {
            $expectedData = realpath(base_path('../.runtime/pgsql'));
            $marker = json_decode(file_get_contents(base_path('../.runtime/civora-cluster.json')), true, flags: JSON_THROW_ON_ERROR);
            $name = $event->input->getOption('database') ?: DB::getDefaultConnection();
            // Laravel's ::read/::write/::direct selectors can open a PDO during connection resolution.
            if (! is_string($name) || str_contains($name, '::')) {
                throw new RuntimeException(self::MESSAGE);
            }
            $configuration = (new ConfigurationUrlParser)->parseConfiguration(config("database.connections.$name"));
            // db:wipe internally selects a configured direct endpoint without another command event.
            if (! empty($configuration['direct']) || ! empty($configuration['pooled'])) {
                throw new RuntimeException(self::MESSAGE);
            }
            $connection = DB::connection($name);
            // Laravel resolves cached configuration and URL overrides before constructing this connection.
            if (! $expectedData || $this->path($marker['data']) !== $this->path($expectedData)
                || $marker['port'] < 1024
                || $connection->getDriverName() !== 'pgsql'
                || $connection->getDatabaseName() !== 'civora_test'
                || $connection->getConfig('host') !== '127.0.0.1'
                || (string) $connection->getConfig('port') !== (string) $marker['port']
                || $connection->getConfig('read') || $connection->getConfig('write')
                || $connection->hasDirectConnection() || $connection->getConfig('pooled')
                || $connection->getConfig('search_path') !== 'public') {
                throw new RuntimeException(self::MESSAGE);
            }

            // Only the validated local write connection may be opened, for a read-only identity query.
            $identity = $connection->selectFromWriteConnection("SELECT current_database() AS database, current_setting('data_directory') AS data_directory")[0];
            if ($identity->database !== 'civora_test' || $this->path($identity->data_directory) !== $this->path($expectedData)) {
                throw new RuntimeException(self::MESSAGE);
            }
        } catch (Throwable) {
            // Fail closed without leaking connection strings, credentials, or database errors.
            throw new RuntimeException(self::MESSAGE);
        }
    }

    private function path(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}

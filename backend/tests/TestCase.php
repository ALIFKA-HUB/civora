<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        if (! $app->environment('testing') || $connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'civora_test') {
            throw new \RuntimeException('Tests require the dedicated PostgreSQL civora_test database.');
        }

        return $app;
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrepareMobileQaFixturesTest extends TestCase
{
    public function test_fixture_command_refuses_a_non_testing_environment_without_writing(): void
    {
        $usersBefore = DB::table('users')->count();
        $recordsBefore = DB::table('point_records')->count();
        Config::set('app.env', 'local');

        self::assertSame(1, Artisan::call('civora:prepare-mobile-qa-fixtures'));
        self::assertSame($usersBefore, DB::table('users')->count());
        self::assertSame($recordsBefore, DB::table('point_records')->count());
        self::assertStringNotContainsString('9000000001', Artisan::output());
        self::assertStringNotContainsString('password', Artisan::output());
    }
}

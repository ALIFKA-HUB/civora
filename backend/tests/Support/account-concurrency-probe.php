<?php

use App\Actions\Accounts\AccountSecurity;
use App\Actions\Accounts\DeactivateAccount;
use App\Actions\Accounts\ResetAccountPassword;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDatabaseName() !== 'civora_test') {
    throw new RuntimeException('Concurrency probe requires civora_test.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
try {
    DB::transaction(function () use ($argv): void {
        if (isset($argv[4])) {
            AccountSecurity::lockAdminChanges();
            file_put_contents($argv[4].'/locked', 'ready');
            $deadline = microtime(true) + 10;
            while (! file_exists($argv[4].'/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrency barrier timed out.');
                }
                usleep(10000);
            }
        }
        $actor = User::findOrFail($argv[1]);
        $action = $argv[2] === 'reset' ? ResetAccountPassword::class : DeactivateAccount::class;
        app($action)->execute($actor, $actor, 'Synthetic concurrent admin action');
    });
    echo 'completed';
} catch (ValidationException $e) {
    echo 'protected';
}

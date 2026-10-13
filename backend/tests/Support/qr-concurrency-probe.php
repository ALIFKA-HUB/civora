<?php

use App\Actions\Points\RedeemStudentQr;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CarbonImmutable::setTestNow((string) getenv('CIVORA_TEST_NOW'));
if (! $app->environment('testing') || DB::getDatabaseName() !== 'civora_test') {
    throw new RuntimeException('QR concurrency probe requires civora_test.');
}
$releaseFile = getenv('CIVORA_TEST_RELEASE_FILE');
$deadline = microtime(true) + 10;
while (! $releaseFile || ! file_exists($releaseFile)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('QR concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(RedeemStudentQr::class)->execute(User::findOrFail($argv[1]), (string) getenv('CIVORA_TEST_QR_TOKEN'), $argv[2]);
    echo 'redeemed';
} catch (ValidationException) {
    echo 'conflict';
}

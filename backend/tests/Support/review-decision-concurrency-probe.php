<?php

use App\Actions\Reviews\DecideReviewRequest;
use App\Models\ReviewRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CarbonImmutable::setTestNow((string) getenv('CIVORA_TEST_NOW'));
if (! $app->environment('testing') || DB::getDatabaseName() !== 'civora_test') {
    throw new RuntimeException('Review concurrency probe requires civora_test.');
}
$admin = User::findOrFail($argv[1]);
$review = ReviewRequest::findOrFail($argv[2]);
$releaseFile = getenv('CIVORA_TEST_RELEASE_FILE');
$readyFile = getenv('CIVORA_TEST_READY_FILE');
if (! $readyFile || ! $releaseFile) {
    throw new RuntimeException('Review concurrency probe requires readiness and release barriers.');
}
$readyDirectory = dirname($readyFile);
if (dirname($releaseFile) !== $readyDirectory) {
    throw new RuntimeException('Review concurrency barriers must share the test directory.');
}
if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to signal review decision readiness.');
}
$deadline = microtime(true) + 10;
while (! file_exists($releaseFile)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Review concurrency barrier timed out.');
    }
    usleep(10000);
}

try {
    app(DecideReviewRequest::class)->execute(
        $admin,
        $review,
        'approve',
        'Parallel admin decision.',
        1,
        ['operation' => 'cancel'],
    );
    echo 'approved';
} catch (HttpResponseException $exception) {
    if ($exception->getResponse()->getStatusCode() !== 409) {
        fwrite(STDERR, 'Unexpected HTTP conflict status: '.$exception->getResponse()->getStatusCode());
        exit(2);
    }
    echo 'conflict-409';
}

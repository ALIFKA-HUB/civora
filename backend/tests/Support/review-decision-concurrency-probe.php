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
$releaseFile = getenv('CIVORA_TEST_RELEASE_FILE');
$deadline = microtime(true) + 10;
while (! $releaseFile || ! file_exists($releaseFile)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Review concurrency barrier timed out.');
    }
    usleep(10000);
}

try {
    app(DecideReviewRequest::class)->execute(
        User::findOrFail($argv[1]),
        ReviewRequest::findOrFail($argv[2]),
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

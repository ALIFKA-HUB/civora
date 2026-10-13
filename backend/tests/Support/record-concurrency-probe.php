<?php

use App\Actions\Academic\MoveStudent;
use App\Actions\Points\RecordPoint;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CarbonImmutable::setTestNow((string) getenv('CIVORA_TEST_NOW'));
if (! $app->environment('testing') || DB::getDatabaseName() !== 'civora_test') {
    throw new RuntimeException('Record concurrency probe requires civora_test.');
}
$releaseFile = getenv('CIVORA_TEST_RELEASE_FILE');
$deadline = microtime(true) + 10;
while (! $releaseFile || ! file_exists($releaseFile)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Record concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    if ($argv[1] === 'record') {
        app(RecordPoint::class)->execute(User::findOrFail($argv[2]), [
            'selection_id' => $argv[3],
            'point_type_version_id' => $argv[4],
            'description' => 'Concurrent record acceptance',
        ], $argv[5]);
        echo 'recorded';
    } else {
        app(MoveStudent::class)->execute(User::findOrFail($argv[2]), Student::findOrFail(DB::table('students')->value('id')), $argv[3], $argv[4]);
        echo 'moved';
    }
} catch (ValidationException|HttpResponseException) {
    echo 'conflict';
}

<?php

// This replaces the requested destructive command with a harmless sentinel write.
// Refusal regressions can therefore exercise CLI dispatch without touching a database schema.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->configurationIsCached()) {
    throw new RuntimeException('Probe configuration cache was not loaded.');
}
Artisan::command($argv[1].' {--database=} {--force}', function () {
    file_put_contents(getenv('CIVORA_GUARD_PROBE_OUTPUT'), 'executed');

    return 0;
});
exit($app->handleCommand(new ArgvInput));

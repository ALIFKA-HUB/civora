<?php

use App\Jobs\DispatchCivoraNotifications;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    OutboxEvent::query()->whereNull('processed_at')
        ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
        ->orderBy('created_at')->limit(100)->pluck('id')
        ->each(fn (string $id) => DispatchCivoraNotifications::dispatch($id));
})->everyMinute()->name('dispatch-civora-notifications')->withoutOverlapping();

Schedule::command('civora:cleanup-temporary-resources')->dailyAt('03:15');

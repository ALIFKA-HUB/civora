<?php

namespace App\Providers;

use App\Database\PostgresGrammar;
use App\Support\GuardDestructiveMigrations;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'pgsql') {
                $event->connection->setQueryGrammar(new PostgresGrammar($event->connection));
            }
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Laravel normally skips command events in testing; CLI safety must apply there too.
            $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
            Event::listen(CommandStarting::class, GuardDestructiveMigrations::class);
        }
    }
}

<?php

namespace App\Providers;

use App\Actions\Accounts\AccountSecurity;
use App\Database\PostgresGrammar;
use App\Support\GuardDestructiveMigrations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::define('access-admin', fn ($user): bool => $user->role === 'admin' && AccountSecurity::active($user));

        RateLimiter::for('account-login', function (Request $request) {
            $identity = $request->input('nisn', $request->input('identifier', ''));
            $identity = is_string($identity) ? mb_strtolower(trim($identity)) : '';

            return [Limit::perMinute(30)->by('login-ip:'.$request->ip()), Limit::perMinute(5)->by('login:'.hash('sha256', $request->path().'|'.$request->ip().'|'.$identity))];
        });
        RateLimiter::for('account-sensitive', fn (Request $request) => Limit::perMinute(5)->by('sensitive:'.$request->user()?->id));
        if ($this->app->runningInConsole()) {
            // Laravel normally skips command events in testing; CLI safety must apply there too.
            $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
            Event::listen(CommandStarting::class, GuardDestructiveMigrations::class);
        }
    }
}

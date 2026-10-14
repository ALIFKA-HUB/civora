<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureAdminRecentAuthentication;
use App\Http\Middleware\EnsureAdminSessionIsFresh;
use App\Http\Middleware\EnsurePendingAdminActivation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', AssignRequestId::class);
        $middleware->alias([
            'admin.session' => EnsureAdminSessionIsFresh::class,
            'admin.active' => EnsureActiveAdmin::class,
            'admin.pending' => EnsurePendingAdminActivation::class,
            'admin.reauthenticated' => EnsureAdminRecentAuthentication::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            [$code, $message] = match ($status) {
                401 => ['UNAUTHENTICATED', 'Sesi tidak sah. Silakan login kembali.'],
                403 => ['FORBIDDEN', 'Akses ditolak.'],
                404 => ['RESOURCE_NOT_FOUND', 'Data tidak ditemukan.'],
                410 => ['TEMPORARY_CREDENTIAL_EXPIRED', 'Password sementara sudah kedaluwarsa.'],
                422 => ['VALIDATION_ERROR', 'Periksa kembali isian Anda.'],
                429 => ['RATE_LIMITED', 'Terlalu banyak percobaan. Silakan coba lagi nanti.'],
                default => ['SERVER_ERROR', 'Terjadi kesalahan pada layanan.'],
            };
            $error = ['code' => $code, 'message' => $message];
            if ($exception instanceof ValidationException) {
                $error['fields'] = $exception->errors();
            }

            return response()->json(['error' => $error, 'request_id' => $request->attributes->get('request_id') ?? (string) Str::uuid()], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation']);
    })->create();

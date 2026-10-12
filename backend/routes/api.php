<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok']));

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:account-login')->group(function (): void {
        Route::post('/auth/student/login', [AuthController::class, 'studentLogin']);
        Route::post('/auth/teacher/login', [AuthController::class, 'teacherLogin']);
    });
    Route::middleware(['auth:sanctum', EnsureActiveAccount::class.':activation'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:account-sensitive');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/teacher/students/{student}/temporary-credential/reveal', [AuthController::class, 'reveal'])
            ->middleware([EnsureActiveAccount::class.':active,teacher', 'throttle:account-sensitive']);
    });
});

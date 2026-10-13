<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PointRecordController;
use App\Http\Controllers\Api\PointTypeController;
use App\Http\Controllers\Api\StudentQrController;
use App\Http\Controllers\Api\StudentSelectionController;
use App\Http\Controllers\Api\TeacherStudentController;
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
        Route::get('/teacher/point-types', [PointTypeController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/student/qr', [StudentQrController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,student');
        Route::get('/teacher/students', [TeacherStudentController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/students/{id}', [TeacherStudentController::class, 'show'])
            ->whereUuid('id')->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/qr/redeem', [StudentSelectionController::class, 'redeem'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/student-selections', [StudentSelectionController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/records', [PointRecordController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/record-attempts/{key}', [PointRecordController::class, 'attempt'])
            ->where('key', '[A-Za-z0-9._:-]{1,100}')
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/attachments', [AttachmentController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/attachments/{id}', [AttachmentController::class, 'show'])
            ->whereUuid('id');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:account-sensitive');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/teacher/students/{student}/temporary-credential/reveal', [AuthController::class, 'reveal'])
            ->middleware([EnsureActiveAccount::class.':active,teacher', 'throttle:account-sensitive']);
    });
});

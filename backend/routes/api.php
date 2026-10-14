<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PointRecordController;
use App\Http\Controllers\Api\PointTypeController;
use App\Http\Controllers\Api\ReviewRequestController;
use App\Http\Controllers\Api\StudentQrController;
use App\Http\Controllers\Api\StudentRecordController;
use App\Http\Controllers\Api\StudentSelectionController;
use App\Http\Controllers\Api\StudentSummaryController;
use App\Http\Controllers\Api\TeacherRecordController;
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
        Route::get('/student/summary', [StudentSummaryController::class, 'show'])
            ->middleware(EnsureActiveAccount::class.':active,student');
        Route::get('/student/records', [StudentRecordController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,student');
        Route::get('/student/records/{record}', [StudentRecordController::class, 'show'])
            ->whereUuid('record')->middleware(EnsureActiveAccount::class.':active,student');
        Route::post('/student/records/{record}/objections', [ReviewRequestController::class, 'object'])
            ->whereUuid('record')->middleware(EnsureActiveAccount::class.':active,student');
        Route::get('/teacher/point-types', [PointTypeController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/student/qr', [StudentQrController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,student');
        Route::get('/teacher/students', [TeacherStudentController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/students/{id}', [TeacherStudentController::class, 'show'])
            ->whereUuid('id')->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/students/{id}/records', [TeacherRecordController::class, 'studentRecords'])
            ->whereUuid('id')->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/qr/redeem', [StudentSelectionController::class, 'redeem'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/student-selections', [StudentSelectionController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/records', [PointRecordController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/records', [TeacherRecordController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/records/{record}', [TeacherRecordController::class, 'show'])
            ->whereUuid('record')->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/records/{record}/correction-requests', [ReviewRequestController::class, 'requestCorrection'])
            ->whereUuid('record')->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/teacher/record-attempts/{key}', [PointRecordController::class, 'attempt'])
            ->where('key', '[A-Za-z0-9._:-]{1,100}')
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::post('/teacher/attachments', [AttachmentController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,teacher');
        Route::get('/attachments/{id}', [AttachmentController::class, 'show'])
            ->whereUuid('id')
            ->middleware(EnsureActiveAccount::class.':active');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:account-sensitive');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->middleware(EnsureActiveAccount::class.':active,student,teacher');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereUuid('notification')->middleware(EnsureActiveAccount::class.':active,student,teacher');
        Route::post('/device-tokens', [DeviceTokenController::class, 'store'])
            ->middleware(EnsureActiveAccount::class.':active,student,teacher');
        Route::delete('/device-tokens/{deviceToken}', [DeviceTokenController::class, 'destroy'])
            ->whereUuid('deviceToken')->middleware(EnsureActiveAccount::class.':active,student,teacher');
        Route::post('/teacher/students/{student}/temporary-credential/reveal', [AuthController::class, 'reveal'])
            ->middleware([EnsureActiveAccount::class.':active,teacher', 'throttle:account-sensitive']);
    });
});

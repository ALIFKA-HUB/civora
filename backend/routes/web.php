<?php

use App\Actions\Reports\DownloadReportExport;
use App\Http\Controllers\Admin\AccountImportTemplateController;
use App\Livewire\Admin\Academic;
use App\Livewire\Admin\AccountsImport;
use App\Livewire\Admin\Activate;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\ConfirmPassword;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\FollowUps;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\PointRecords;
use App\Livewire\Admin\PointTypes;
use App\Livewire\Admin\Reports;
use App\Livewire\Admin\ReviewDetail;
use App\Livewire\Admin\ReviewQueue;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\StudentDetail;
use App\Livewire\Admin\Students;
use App\Livewire\Admin\Teachers;
use App\Livewire\Admin\Thresholds;
use App\Models\Attachment;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', Login::class)->middleware('guest')->name('login');

    Route::middleware(['auth', 'admin.session'])->group(function (): void {
        Route::post('/logout', function (Request $request) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('logout');

        Route::get('/activate', Activate::class)->middleware('admin.pending')->name('activate');
        Route::get('/confirm-password', ConfirmPassword::class)->can('access-admin')->name('confirm-password');

        Route::get('/', Dashboard::class)->middleware('admin.active')->name('home');
        Route::middleware('admin.active')->group(function (): void {
            Route::get('/students', Students::class)->name('students');
            Route::get('/students/{studentId}', StudentDetail::class)->whereUuid('studentId')->name('students.show');
            Route::get('/teachers', Teachers::class)->name('teachers');
            Route::get('/academic', Academic::class)->name('academic');
            Route::get('/imports', AccountsImport::class)->name('import');
            Route::get('/imports/template/{entityType}/{format}', AccountImportTemplateController::class)->name('import.template');
            Route::get('/point-types', PointTypes::class)->name('point-types');
            Route::get('/thresholds', Thresholds::class)->name('thresholds');
            Route::get('/follow-ups', FollowUps::class)->name('follow-ups');
            Route::get('/reports', Reports::class)->name('reports');
            Route::get('/reports/exports/{reportExport}/download', function (ReportExport $reportExport) {
                return app(DownloadReportExport::class)->execute(auth('web')->user(), $reportExport->id);
            })->middleware('admin.reauthenticated')->name('reports.download');
            Route::get('/settings', Settings::class)->name('settings');
            Route::get('/audit', AuditLogViewer::class)->name('audit');
            Route::get('/point-records', PointRecords::class)->name('point-records');
            Route::get('/reviews', ReviewQueue::class)->name('reviews');
            Route::get('/reviews/{reviewId}', ReviewDetail::class)->whereUuid('reviewId')->name('reviews.show');
            Route::get('/attachments/{attachment}', function (Attachment $attachment) {
                Gate::authorize('view', $attachment);
                abort_unless(Storage::disk('local')->exists($attachment->private_path), 404, 'Data tidak ditemukan.');
                $stream = Storage::disk('local')->readStream($attachment->private_path);
                abort_unless(is_resource($stream), 404, 'Data tidak ditemukan.');

                return response()->stream(function () use ($stream): void {
                    fpassthru($stream);
                    fclose($stream);
                }, 200, [
                    'Content-Type' => $attachment->mime_type,
                    'Content-Length' => (string) Storage::disk('local')->size($attachment->private_path),
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            })->name('attachments.show');
        });
    });
});

<?php

use App\Http\Controllers\Admin\AccountImportTemplateController;
use App\Livewire\Admin\Academic;
use App\Livewire\Admin\AccountsImport;
use App\Livewire\Admin\Activate;
use App\Livewire\Admin\ConfirmPassword;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\StudentDetail;
use App\Livewire\Admin\Students;
use App\Livewire\Admin\Teachers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        });
    });
});

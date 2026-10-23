<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\InspectionTemplateController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VanController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WorkerShiftController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/activity', ActivityLogController::class)->name('activity.index');
        Route::resource('users', UserController::class)->except('show');
        Route::resource('vans', VanController::class)->except(['show', 'destroy']);
        Route::resource('templates', InspectionTemplateController::class)->except(['show', 'destroy']);
        Route::resource('shifts', ShiftController::class)->only(['index', 'create', 'store', 'show']);
    });
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/shifts', [WorkerShiftController::class, 'index'])->name('shifts.index');
    Route::get('/shifts/{shift}', [WorkerShiftController::class, 'show'])->name('shifts.show');
    Route::get('/shifts/{shift}/checks/{check}', [WorkerShiftController::class, 'check'])->scopeBindings()->name('shifts.checks.show');
    Route::view('/profile', 'profile')->name('profile.show');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

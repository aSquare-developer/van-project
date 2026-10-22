<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
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
        Route::resource('users', UserController::class)->except('show');
    });
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/profile', 'profile')->name('profile.show');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

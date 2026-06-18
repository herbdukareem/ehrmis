<?php

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'spa')->name('login');
Route::view('/dashboard', 'spa')->name('dashboard');
Route::view('/forgot-password', 'spa')->name('password.request');
Route::view('/reset-password/{token}', 'spa')->name('password.reset');
Route::view('/verify-email', 'spa')->middleware('auth')->name('verification.notice');
Route::view('/confirm-password', 'spa')->middleware('auth')->name('password.confirm');
Route::view('/profile', 'spa')->middleware('auth')->name('profile.edit');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('guest')->name('password.email');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('guest')->name('password.store');

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::view('/{path?}', 'spa')
    ->where('path', '^(?!api|storage|up).*$')
    ->name('spa');

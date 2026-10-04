<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:30,1');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
        ->name('two-factor.verify');
    Route::post('two-factor-challenge/cancel', [TwoFactorChallengeController::class, 'cancel'])
        ->name('two-factor.cancel');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::post('verify-password', [ConfirmablePasswordController::class, 'verify'])
        ->middleware('throttle:5,1')
        ->name('password.verify');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
});

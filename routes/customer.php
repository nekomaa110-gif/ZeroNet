<?php

use App\Http\Controllers\Customer\AuthController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\InvoiceController;
use App\Http\Controllers\Customer\PhoneController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:customer')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('customer.login');
    Route::post('/login', [AuthController::class, 'login'])->name('customer.login.attempt');
});

Route::middleware('auth:customer')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('customer.logout');

    Route::get('/pelanggan/register-phone', [PhoneController::class, 'show'])->name('customer.phone.show');
    Route::post('/pelanggan/register-phone', [PhoneController::class, 'store'])->name('customer.phone.store');
});

Route::middleware(['auth:customer', 'customer.phone'])->prefix('pelanggan')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('customer.dashboard');
    Route::post('/renew-request', [DashboardController::class, 'requestRenewal'])->name('customer.renew.request');

    Route::get('/invoice', [InvoiceController::class, 'index'])->name('customer.invoice.index');
    Route::get('/invoice/{invoice}', [InvoiceController::class, 'show'])->name('customer.invoice.show');
    Route::post('/invoice/{invoice}/upload-proof', [InvoiceController::class, 'uploadProof'])->name('customer.invoice.upload-proof');
});

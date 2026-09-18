<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProofController as AdminProofController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/registrieren', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/registrieren', [AuthController::class, 'register'])->name('register.submit');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/angebote', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/angebote/{offer:slug}', [OfferController::class, 'show'])->name('offers.show');
    Route::post('/angebote/{offer}/annehmen', [OrderController::class, 'store'])->name('offers.accept');

    Route::get('/auftraege', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/auftraege/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/auftraege/{order}/start', [OrderController::class, 'start'])->name('orders.start');

    Route::post('/auftragstage/{day}/nachweise', [ProofController::class, 'store'])->name('proofs.store');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/auszahlung', [WalletController::class, 'payout'])->name('wallet.payout');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::get('/angebote', [AdminOfferController::class, 'index'])->name('offers.index');
    Route::get('/angebote/neu', [AdminOfferController::class, 'create'])->name('offers.create');
    Route::post('/angebote', [AdminOfferController::class, 'store'])->name('offers.store');
    Route::get('/angebote/{offer}/bearbeiten', [AdminOfferController::class, 'edit'])->name('offers.edit');
    Route::put('/angebote/{offer}', [AdminOfferController::class, 'update'])->name('offers.update');

    Route::get('/auftraege', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/auftraege/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::post('/auftraege/{order}/status', [AdminOrderController::class, 'status'])->name('orders.status');
    Route::post('/auftraege/{order}/verguetung-freigeben', [AdminOrderController::class, 'release'])->name('orders.release');

    Route::get('/nachweise', [AdminProofController::class, 'index'])->name('proofs.index');
    Route::get('/nachweise/{proof}/datei', [AdminProofController::class, 'file'])->name('proofs.file');
    Route::post('/nachweise/{proof}/pruefen', [AdminProofController::class, 'review'])->name('proofs.review');
});

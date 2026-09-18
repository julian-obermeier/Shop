<?php

use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\GoodsReceiptController as AdminGoodsReceiptController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PrecheckController as AdminPrecheckController;
use App\Http\Controllers\Admin\ProofController as AdminProofController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrecheckController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\VerificationController;
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

    Route::get('/verifizierung', [VerificationController::class, 'index'])->name('verification.index');
    Route::post('/verifizierung', [VerificationController::class, 'store'])->name('verification.store');

    Route::get('/angebote', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/angebote/{offer:slug}', [OfferController::class, 'show'])->name('offers.show');
    Route::post('/angebote/{offer}/annehmen', [OrderController::class, 'store'])->name('offers.accept');

    Route::get('/auftraege', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/auftraege/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/auftraege/{order}/vorpruefung', [PrecheckController::class, 'store'])->name('orders.precheck');
    Route::post('/auftraege/{order}/start', [OrderController::class, 'start'])->name('orders.start');
    Route::post('/auftraege/{order}/abschliessen', [OrderController::class, 'complete'])->name('orders.complete');
    Route::post('/auftraege/{order}/versand', [ShipmentController::class, 'store'])->name('orders.shipment');

    Route::post('/auftragstage/{day}/nachweise', [ProofController::class, 'store'])->name('proofs.store');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/auszahlung', [WalletController::class, 'payout'])->name('wallet.payout');

    Route::get('/nachrichten', [ConversationController::class, 'index'])->name('messages.index');
    Route::post('/nachrichten', [ConversationController::class, 'store'])->name('messages.store');
    Route::get('/nachrichten/{conversation}', [ConversationController::class, 'show'])->name('messages.show');
    Route::post('/nachrichten/{conversation}/antwort', [ConversationController::class, 'reply'])->name('messages.reply');

    Route::get('/dokumente', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/dokumente/version/{version}/zustimmen', [DocumentController::class, 'consent'])->name('documents.consent');
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
    Route::post('/auftraege/{order}/wareneingang', [AdminGoodsReceiptController::class, 'store'])->name('orders.goods-receipt');
    Route::post('/auftraege/{order}/verguetung-freigeben', [AdminOrderController::class, 'release'])->name('orders.release');

    Route::get('/nachweise', [AdminProofController::class, 'index'])->name('proofs.index');
    Route::get('/nachweise/{proof}/datei', [AdminProofController::class, 'file'])->name('proofs.file');
    Route::post('/nachweise/{proof}/pruefen', [AdminProofController::class, 'review'])->name('proofs.review');

    Route::get('/verifizierungen', [AdminVerificationController::class, 'index'])->name('verifications.index');
    Route::get('/verifizierungen/{verification}/datei/{side}', [AdminVerificationController::class, 'file'])->name('verifications.file');
    Route::post('/verifizierungen/{verification}/pruefen', [AdminVerificationController::class, 'review'])->name('verifications.review');

    Route::get('/vorpruefungen', [AdminPrecheckController::class, 'index'])->name('prechecks.index');
    Route::get('/vorpruefungen/{precheck}/datei', [AdminPrecheckController::class, 'file'])->name('prechecks.file');
    Route::post('/vorpruefungen/{precheck}/pruefen', [AdminPrecheckController::class, 'review'])->name('prechecks.review');

    Route::get('/nachrichten', [AdminConversationController::class, 'index'])->name('messages.index');
    Route::get('/nachrichten/{conversation}', [AdminConversationController::class, 'show'])->name('messages.show');
    Route::post('/nachrichten/{conversation}/antwort', [AdminConversationController::class, 'reply'])->name('messages.reply');
    Route::post('/nachrichten/{conversation}/schliessen', [AdminConversationController::class, 'close'])->name('messages.close');

    Route::get('/dokumente', [AdminDocumentController::class, 'index'])->name('documents.index');
    Route::post('/dokumente', [AdminDocumentController::class, 'store'])->name('documents.store');
});

<?php

use App\Http\Controllers\Admin\AdminUserController as AdminTeamController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\GoodsReceiptController as AdminGoodsReceiptController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\PrecheckController as AdminPrecheckController;
use App\Http\Controllers\Admin\ProofController as AdminProofController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MessageFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrecheckController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/passwort-vergessen', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/passwort-vergessen', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/passwort-zuruecksetzen/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/passwort-zuruecksetzen', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');

    Route::get('/zwei-faktor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/zwei-faktor', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1')->name('two-factor.verify');
    Route::post('/zwei-faktor/neu', [TwoFactorController::class, 'resend'])->middleware('throttle:2,1')->name('two-factor.resend');
});

Route::middleware(['auth','active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/email-bestaetigen', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email-bestaetigen/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email-bestaetigung-senden', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1')->name('verification.send');

    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/benachrichtigungen', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/benachrichtigungen/alle-gelesen', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/benachrichtigungen/{notification}/oeffnen', [NotificationController::class, 'read'])->name('notifications.read');

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
    Route::get('/nachrichten/anlage/{message}', MessageFileController::class)->name('messages.attachment');
    Route::post('/nachrichten', [ConversationController::class, 'store'])->name('messages.store');
    Route::get('/nachrichten/{conversation}', [ConversationController::class, 'show'])->name('messages.show');
    Route::post('/nachrichten/{conversation}/antwort', [ConversationController::class, 'reply'])->name('messages.reply');

    Route::get('/dokumente', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/dokumente/version/{version}/zustimmen', [DocumentController::class, 'consent'])->name('documents.consent');
});

Route::prefix('admin')->name('admin.')->middleware(['auth','active','admin'])->group(function () {
    Route::get('/', AdminDashboardController::class)->middleware('permission:admin.dashboard')->name('dashboard');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/anbieterinnen', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/anbieterinnen/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::post('/anbieterinnen/{user}/verwarnung', [AdminUserController::class, 'warning'])->name('users.warning');
        Route::post('/anbieterinnen/{user}/sperre', [AdminUserController::class, 'restriction'])->name('users.restriction');
        Route::post('/anbieterinnen/{user}/sperre/{restriction}/aufheben', [AdminUserController::class, 'removeRestriction'])->name('users.restriction.remove');
    });

    Route::middleware('permission:categories.manage')->group(function () {
        Route::get('/kategorien', [AdminCategoryController::class, 'index'])->name('categories.index');
        Route::post('/kategorien', [AdminCategoryController::class, 'store'])->name('categories.store');
        Route::put('/kategorien/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    });

    Route::middleware('permission:offers.manage')->group(function () {
        Route::get('/angebote', [AdminOfferController::class, 'index'])->name('offers.index');
        Route::get('/angebote/neu', [AdminOfferController::class, 'create'])->name('offers.create');
        Route::post('/angebote', [AdminOfferController::class, 'store'])->name('offers.store');
        Route::get('/angebote/{offer}/bearbeiten', [AdminOfferController::class, 'edit'])->name('offers.edit');
        Route::put('/angebote/{offer}', [AdminOfferController::class, 'update'])->name('offers.update');
    });

    Route::middleware('permission:orders.manage')->group(function () {
        Route::get('/auftraege', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/auftraege/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::post('/auftraege/{order}/status', [AdminOrderController::class, 'status'])->name('orders.status');
        Route::post('/auftraege/{order}/wareneingang', [AdminGoodsReceiptController::class, 'store'])->name('orders.goods-receipt');
        Route::post('/auftraege/{order}/verguetung-freigeben', [AdminOrderController::class, 'release'])->name('orders.release');

        Route::get('/vorpruefungen', [AdminPrecheckController::class, 'index'])->name('prechecks.index');
        Route::get('/vorpruefungen/{precheck}/datei', [AdminPrecheckController::class, 'file'])->name('prechecks.file');
        Route::post('/vorpruefungen/{precheck}/pruefen', [AdminPrecheckController::class, 'review'])->name('prechecks.review');
    });

    Route::middleware('permission:proofs.manage')->group(function () {
        Route::get('/nachweise', [AdminProofController::class, 'index'])->name('proofs.index');
        Route::get('/nachweise/{proof}/datei', [AdminProofController::class, 'file'])->name('proofs.file');
        Route::post('/nachweise/{proof}/pruefen', [AdminProofController::class, 'review'])->name('proofs.review');
    });

    Route::middleware('permission:verification.manage')->group(function () {
        Route::get('/verifizierungen', [AdminVerificationController::class, 'index'])->name('verifications.index');
        Route::get('/verifizierungen/{verification}/datei/{side}', [AdminVerificationController::class, 'file'])->name('verifications.file');
        Route::post('/verifizierungen/{verification}/pruefen', [AdminVerificationController::class, 'review'])->name('verifications.review');
    });

    Route::middleware('permission:payouts.manage')->group(function () {
        Route::get('/auszahlungen', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('/auszahlungen/{payout}', [AdminPayoutController::class, 'update'])->name('payouts.update');
    });

    Route::middleware('permission:messages.manage')->group(function () {
        Route::get('/nachrichten', [AdminConversationController::class, 'index'])->name('messages.index');
        Route::get('/nachrichten/{conversation}', [AdminConversationController::class, 'show'])->name('messages.show');
        Route::post('/nachrichten/{conversation}/antwort', [AdminConversationController::class, 'reply'])->name('messages.reply');
        Route::post('/nachrichten/{conversation}/schliessen', [AdminConversationController::class, 'close'])->name('messages.close');
    });

    Route::middleware('permission:documents.manage')->group(function () {
        Route::get('/dokumente', [AdminDocumentController::class, 'index'])->name('documents.index');
        Route::post('/dokumente', [AdminDocumentController::class, 'store'])->name('documents.store');
    });

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/berichte', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/berichte/export/{type}', [AdminReportController::class, 'export'])->name('reports.export');
    });

    Route::get('/audit', [AdminAuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/team', [AdminTeamController::class, 'index'])->name('admin-users.index');
        Route::post('/team', [AdminTeamController::class, 'store'])->name('admin-users.store');
        Route::put('/team/{adminUser}', [AdminTeamController::class, 'update'])->name('admin-users.update');

        Route::get('/einstellungen', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('/einstellungen', [AdminSettingsController::class, 'update'])->name('settings.update');
    });
});

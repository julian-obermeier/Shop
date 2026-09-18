<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\GoodsReceiptController as AdminGoodsReceiptController;
use App\Http\Controllers\Admin\GoodsInspectionController as AdminGoodsInspectionController;
use App\Http\Controllers\Admin\HealthController as AdminHealthController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\PrecheckController as AdminPrecheckController;
use App\Http\Controllers\Admin\PrivacyController as AdminPrivacyController;
use App\Http\Controllers\Admin\ProofController as AdminProofController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReturnRequestController as AdminReturnRequestController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\ShipmentController as AdminShipmentController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UnassignedShipmentController as AdminUnassignedShipmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MessageFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferWaitlistController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrecheckController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReturnRequestController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
    Route::get('/registrieren', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/registrieren', [AuthController::class, 'register'])->middleware('throttle:5,10')->name('register.submit');

    Route::get('/passwort-vergessen', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/passwort-vergessen', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/passwort-zuruecksetzen/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/passwort-zuruecksetzen', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');});

Route::middleware(['auth','active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/email-bestaetigen', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email-bestaetigen/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email-bestaetigung-senden', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1')->name('verification.send');

    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profil/email', [AccountSecurityController::class, 'updateEmail'])->middleware('throttle:5,10')->name('profile.email');
    Route::put('/profil/passwort', [AccountSecurityController::class, 'updatePassword'])->middleware('throttle:5,10')->name('profile.password');

    Route::get('/benachrichtigungen', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/benachrichtigungen/push', [PushSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('notifications.push.store');
    Route::delete('/benachrichtigungen/push', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:10,1')->name('notifications.push.destroy');
    Route::post('/benachrichtigungen/alle-gelesen', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/benachrichtigungen/{notification}/oeffnen', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/angebote', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/angebote/{offer:slug}', [OfferController::class, 'show'])->name('offers.show');
    Route::post('/angebote/{offer}/annehmen', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('offers.accept');
    Route::post('/angebote/{offer}/warteliste', [OfferWaitlistController::class, 'join'])->name('offers.waitlist.join');
    Route::delete('/angebote/{offer}/warteliste', [OfferWaitlistController::class, 'leave'])->name('offers.waitlist.leave');

    Route::get('/auftraege', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/auftraege/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/auftraege/{order}/vorpruefung', [PrecheckController::class, 'store'])->middleware('throttle:10,10')->name('orders.precheck');
    Route::post('/auftraege/{order}/termin-bestaetigen', [OrderController::class, 'acceptDate'])->name('orders.accept-date');
    Route::post('/auftraege/{order}/zurueckziehen', [OrderController::class, 'withdraw'])->name('orders.withdraw');
    Route::post('/auftraege/{order}/start', [OrderController::class, 'start'])->name('orders.start');
    Route::post('/auftraege/{order}/abschliessen', [OrderController::class, 'complete'])->name('orders.complete');
    Route::post('/auftraege/{order}/abbrechen', [OrderController::class, 'abort'])->name('orders.abort');
    Route::post('/auftraege/{order}/versand', [ShipmentController::class, 'store'])->name('orders.shipment');
    Route::post('/auftraege/{order}/ruecksendung', [ReturnRequestController::class, 'store'])->name('orders.return-request');

    Route::post('/auftragstage/{day}/nachweiscode', [ProofController::class, 'challenge'])->middleware('throttle:20,10')->name('proofs.challenge');
    Route::post('/auftragstage/{day}/nachweise', [ProofController::class, 'store'])->middleware('throttle:30,10')->name('proofs.store');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::put('/wallet/auszahlungsdaten', [WalletController::class, 'updatePayoutDetails'])->middleware('throttle:5,10')->name('wallet.payout-details');
    Route::post('/wallet/auszahlung', [WalletController::class, 'payout'])->middleware('throttle:5,10')->name('wallet.payout');
    Route::post('/wallet/auszahlung/{payout}/stornieren', [WalletController::class, 'cancelPayout'])->name('wallet.payout.cancel');

    Route::get('/nachrichten', [ConversationController::class, 'index'])->name('messages.index');
    Route::get('/nachrichten/anlage/{message}', MessageFileController::class)->name('messages.attachment');
    Route::post('/nachrichten', [ConversationController::class, 'store'])->middleware('throttle:20,1')->name('messages.store');
    Route::get('/nachrichten/{conversation}', [ConversationController::class, 'show'])->name('messages.show');
    Route::post('/nachrichten/{conversation}/antwort', [ConversationController::class, 'reply'])->middleware('throttle:30,1')->name('messages.reply');

    Route::get('/datenschutz', [PrivacyController::class, 'index'])->name('privacy.index');
    Route::get('/datenschutz/export', [PrivacyController::class, 'export'])->middleware('throttle:3,1')->name('privacy.export');

    Route::get('/dokumente', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/dokumente/version/{version}/zustimmen', [DocumentController::class, 'consent'])->name('documents.consent');
});

Route::prefix('admin')->name('admin.')->middleware(['auth','active','admin'])->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    
        Route::get('/anbieterinnen', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/anbieterinnen/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::put('/anbieterinnen/{user}/stammdaten', [AdminUserController::class, 'updateMasterData'])->name('users.master-data');
        Route::post('/anbieterinnen/{user}/auszahlungsempfaenger-freigeben', [AdminUserController::class, 'approvePayoutName'])->name('users.payout-name-approve');
        Route::post('/anbieterinnen/{user}/wallet-override', [AdminUserController::class, 'walletOverride'])->name('users.wallet-override');
        Route::post('/anbieterinnen/{user}/deaktivieren', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/anbieterinnen/{user}/reaktivieren', [AdminUserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('/anbieterinnen/{user}/verwarnung', [AdminUserController::class, 'warning'])->name('users.warning');
        Route::post('/anbieterinnen/{user}/sperre', [AdminUserController::class, 'restriction'])->name('users.restriction');
        Route::post('/anbieterinnen/{user}/sperre/{restriction}/aufheben', [AdminUserController::class, 'removeRestriction'])->name('users.restriction.remove');
    

    
        Route::get('/kategorien', [AdminCategoryController::class, 'index'])->name('categories.index');
        Route::post('/kategorien', [AdminCategoryController::class, 'store'])->name('categories.store');
        Route::put('/kategorien/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    

    
        Route::get('/angebote', [AdminOfferController::class, 'index'])->name('offers.index');
        Route::get('/angebote/neu', [AdminOfferController::class, 'create'])->name('offers.create');
        Route::post('/angebote', [AdminOfferController::class, 'store'])->name('offers.store');
        Route::get('/angebote/{offer}/bearbeiten', [AdminOfferController::class, 'edit'])->name('offers.edit');
        Route::put('/angebote/{offer}', [AdminOfferController::class, 'update'])->name('offers.update');
        Route::post('/angebote/{offer}/duplizieren', [AdminOfferController::class, 'duplicate'])->name('offers.duplicate');
        Route::delete('/angebote/{offer}', [AdminOfferController::class, 'destroy'])->name('offers.destroy');
    

    
        Route::get('/auftraege', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/auftraege/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::post('/auftraege/{order}/bestaetigen', [AdminOrderController::class, 'approve'])->name('orders.approve');
        Route::post('/auftraege/{order}/anfrage-ablehnen', [AdminOrderController::class, 'rejectRequest'])->name('orders.reject-request');
        Route::post('/auftraege/{order}/anfrage-wieder-oeffnen', [AdminOrderController::class, 'reopenRequest'])->name('orders.reopen-request');
        Route::post('/auftraege/{order}/termin-vorschlagen', [AdminOrderController::class, 'proposeDate'])->name('orders.propose-date');
        Route::post('/auftraege/{order}/status', [AdminOrderController::class, 'status'])->name('orders.status');
        Route::post('/auftraege/{order}/fortsetzen', [AdminOrderController::class, 'resume'])->name('orders.resume');
        Route::post('/auftraege/{order}/anforderungen', [AdminOrderController::class, 'updateRequirements'])->name('orders.requirements');
        Route::post('/auftraege/{order}/wareneingang', [AdminGoodsReceiptController::class, 'store'])->name('orders.goods-receipt');
        Route::post('/auftraege/{order}/warenpruefung', [AdminGoodsInspectionController::class, 'store'])->name('orders.goods-inspection');
        Route::get('/ruecksendungen/{returnRequest}/label', [AdminReturnRequestController::class, 'label'])->name('returns.label');
        Route::post('/ruecksendungen/{returnRequest}/kosten', [AdminReturnRequestController::class, 'quote'])->name('returns.quote');
        Route::post('/ruecksendungen/{returnRequest}/zahlung-bestaetigen', [AdminReturnRequestController::class, 'confirmPayment'])->name('returns.confirm-payment');
        Route::post('/ruecksendungen/{returnRequest}/abschliessen', [AdminReturnRequestController::class, 'complete'])->name('returns.complete');
        Route::get('/versandnachweise/{evidence}/datei', [AdminShipmentController::class, 'evidence'])->name('shipments.evidence');
        Route::post('/versand/{shipment}/pruefen', [AdminShipmentController::class, 'review'])->name('shipments.review');
        Route::post('/auftraege/{order}/verguetung-freigeben', [AdminOrderController::class, 'release'])->name('orders.release');
        Route::get('/nicht-zuordenbare-sendungen', [AdminUnassignedShipmentController::class, 'index'])->name('unassigned-shipments.index');
        Route::post('/nicht-zuordenbare-sendungen', [AdminUnassignedShipmentController::class, 'store'])->name('unassigned-shipments.store');

        Route::get('/vorpruefungen', [AdminPrecheckController::class, 'index'])->name('prechecks.index');
        Route::get('/vorpruefungen/{precheck}/datei', [AdminPrecheckController::class, 'file'])->name('prechecks.file');
        Route::post('/vorpruefungen/{precheck}/pruefen', [AdminPrecheckController::class, 'review'])->name('prechecks.review');
    

    
        Route::get('/nachweise', [AdminProofController::class, 'index'])->name('proofs.index');
        Route::get('/nachweise/{proof}/datei', [AdminProofController::class, 'file'])->name('proofs.file');
        Route::post('/nachweise/{proof}/pruefen', [AdminProofController::class, 'review'])->name('proofs.review');
        Route::post('/nachweise/{proof}/zusatzversuch', [AdminProofController::class, 'grantExtraRetry'])->name('proofs.extra-retry');
    
    
        Route::get('/auszahlungen', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('/auszahlungen/{payout}', [AdminPayoutController::class, 'update'])->name('payouts.update');
    

    
        Route::get('/nachrichten', [AdminConversationController::class, 'index'])->name('messages.index');
        Route::get('/nachrichten/{conversation}', [AdminConversationController::class, 'show'])->name('messages.show');
        Route::post('/nachrichten/{conversation}/antwort', [AdminConversationController::class, 'reply'])->name('messages.reply');
    

    
        Route::get('/datenschutz', [AdminPrivacyController::class, 'index'])->name('privacy.index');
        Route::get('/datenschutz/{privacyRequest}', [AdminPrivacyController::class, 'show'])->name('privacy.show');
        Route::post('/datenschutz/{privacyRequest}/pruefen', [AdminPrivacyController::class, 'review'])->name('privacy.review');
        Route::post('/datenschutz/{privacyRequest}/anonymisieren', [AdminPrivacyController::class, 'anonymize'])->name('privacy.anonymize');
    

    
        Route::get('/dokumente', [AdminDocumentController::class, 'index'])->name('documents.index');
        Route::post('/dokumente', [AdminDocumentController::class, 'store'])->name('documents.store');
    

    
        Route::get('/berichte', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/berichte/export/{type}', [AdminReportController::class, 'export'])->name('reports.export');
    

    Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit.index');

    
        Route::get('/systemzustand', AdminHealthController::class)->name('health.index');

        Route::get('/einstellungen', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::put('/einstellungen', [AdminSettingsController::class, 'update'])->name('settings.update');
    
});

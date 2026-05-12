<?php

use App\Http\Controllers\AddressBookController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LocationCascadeController;
use App\Http\Controllers\Api\ShipmentWizardController;
use App\Http\Controllers\AssistedPurchaseAnalyticsController;
use App\Http\Controllers\AssistedPurchaseController;
use App\Http\Controllers\AssistedPurchasePublicController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CustomerPackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FinanceDashboardController;
use App\Http\Controllers\FormDraftController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LockerController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PickupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\RegroupementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SectionDashboardController;
use App\Http\Controllers\Settings\AgencyController;
use App\Http\Controllers\Settings\AgencyPaymentCoordinateController;
use App\Http\Controllers\Settings\AppSettingController;
use App\Http\Controllers\Settings\ArticleCategoryController;
use App\Http\Controllers\Settings\BillingExtraController;
use App\Http\Controllers\Settings\LocationController;
use App\Http\Controllers\Settings\NotificationTemplateController;
use App\Http\Controllers\Settings\PackagingTypeController;
use App\Http\Controllers\Settings\PaymentGatewayController;
use App\Http\Controllers\Settings\PaymentMethodController;
use App\Http\Controllers\Settings\PricingRuleController;
use App\Http\Controllers\Settings\SettingsHubController;
use App\Http\Controllers\Settings\ShipLineController;
use App\Http\Controllers\Settings\ShippingModeController;
use App\Http\Controllers\Settings\SmtpConfigController;
use App\Http\Controllers\Settings\TransportCompanyController;
use App\Http\Controllers\Settings\TwilioConfigController;
use App\Http\Controllers\Settings\PickupFailureReasonController;
use App\Http\Controllers\Settings\RolePermissionController;
use App\Http\Controllers\Settings\ZoneController;
use App\Http\Controllers\QuoteDashboardController;
use App\Http\Controllers\QuoteResponseController;
use App\Http\Controllers\Settings\QuoteLineTemplateController;
use App\Http\Controllers\Settings\QuoteSettingsController;
use App\Http\Controllers\Settings\QuoteTemplateController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentNoticeController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
*/
Route::get('/ping', fn () => response()->json(['status' => 'ok']));

/** Logo, favicon, noms d’affichage — accessible sans droit « paramètres » (sidebar, onglet, login). */
Route::get('/branding', [AppSettingController::class, 'branding']);

/** Fuseaux IANA (liste statique) : public + cache serveur — évite auth Sanctum / requêtes user sur chaque chargement du profil. */
Route::get('/locations/timezones', [LocationCascadeController::class, 'timezones']);

/*
|--------------------------------------------------------------------------
| Authentication (public)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store']);
    Route::post('reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('token', [AuthController::class, 'createToken']);
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated API routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // --- Dashboard ---
    Route::get('dashboard', DashboardController::class);
    Route::get('dashboard/inbound', [SectionDashboardController::class, 'inbound']);
    Route::get('dashboard/shipments', [SectionDashboardController::class, 'shipments']);
    Route::get('dashboard/pickups', [SectionDashboardController::class, 'pickups']);
    Route::get('dashboard/regroupements', [SectionDashboardController::class, 'regroupements']);
    Route::get('dashboard/crm', [SectionDashboardController::class, 'crm']);
    Route::get('dashboard/reports', [SectionDashboardController::class, 'reports']);
    Route::get('dashboard/analytics', [\App\Http\Controllers\AnalyticsDashboardController::class, 'analytics'])->middleware('permission:view_analytics');
    Route::get('dashboard/overdue', [\App\Http\Controllers\AnalyticsDashboardController::class, 'overdue'])->middleware('permission:view_analytics');

    // --- Form Drafts ---
    Route::apiResource('drafts', FormDraftController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // --- Client Portal ---
    Route::prefix('client')->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\ClientPortalController::class, 'dashboard']);
        Route::get('locker', [\App\Http\Controllers\ClientPortalController::class, 'locker']);
        Route::get('invoices', [\App\Http\Controllers\ClientPortalController::class, 'invoices']);
        Route::get('notification-preferences', [\App\Http\Controllers\ClientPortalController::class, 'notificationPreferences']);
        Route::patch('notification-preferences', [\App\Http\Controllers\ClientPortalController::class, 'updateNotificationPreferences']);
    });

    // --- Profile & Theme ---
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::delete('profile', [ProfileController::class, 'destroy']);
    Route::patch('theme', [ThemeController::class, 'update']);

    // --- Locker ---
    Route::get('locker', [LockerController::class, 'show'])->middleware('permission:view_lockers');

    // --- Customer Packages ---
    Route::get('customer-packages', [CustomerPackageController::class, 'index']);
    Route::post('customer-packages', [CustomerPackageController::class, 'store']);
    Route::get('customer-packages/{customerPackage}', [CustomerPackageController::class, 'show']);
    Route::post('customer-packages/{customerPackage}/update-status', [CustomerPackageController::class, 'updateStatus']);

    // --- Shipment Notices ---
    Route::apiResource('shipment-notices', ShipmentNoticeController::class)
        ->parameters(['shipment-notices' => 'shipmentNotice:id']);
    Route::post('shipment-notices/{shipmentNotice}/receive', [ShipmentNoticeController::class, 'receive']);
    Route::post('shipment-notices/{shipmentNotice}/report-issue', [ShipmentNoticeController::class, 'reportIssue']);

    // --- Marchands (shopping assisté : liste active pour les clients) ---
    Route::get('merchants', [MerchantController::class, 'active']);

    // --- Shopping assisté (demandes par lien produit) ---
    Route::get('assisted-purchases', [AssistedPurchaseController::class, 'index']);
    Route::post('assisted-purchases', [AssistedPurchaseController::class, 'store']);
    Route::get('assisted-purchases/{assisted_purchase}', [AssistedPurchaseController::class, 'show']);
    Route::post('assisted-purchases/{assisted_purchase}/quote-preview', [AssistedPurchaseController::class, 'quotePreview'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/quote', [AssistedPurchaseController::class, 'quote'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/mark-ordered', [AssistedPurchaseController::class, 'markAsOrdered'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/resend-quote', [AssistedPurchaseController::class, 'resendQuote'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/mark-paid', [AssistedPurchaseController::class, 'markPaid'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/publish-payment-request', [AssistedPurchaseController::class, 'publishPaymentRequest'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/reject-payment-proof', [AssistedPurchaseController::class, 'rejectPaymentProof'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/update-status', [AssistedPurchaseController::class, 'updateStatus'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/client-payment-ack', [AssistedPurchaseController::class, 'clientPaymentAck']);
    Route::get('assisted-purchases/{assisted_purchase}/payment-proof', [AssistedPurchaseController::class, 'downloadPaymentProof']);
    Route::post('assisted-purchases/extract-product', [AssistedPurchaseController::class, 'extractProduct']);
    Route::get('assisted-purchases/extract-product/{cacheKey}', [AssistedPurchaseController::class, 'extractProductResult']);
    Route::post('assisted-purchases/{assisted_purchase}/convert-to-shipment', [AssistedPurchaseController::class, 'convertToShipment'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/quote-dynamic', [AssistedPurchaseController::class, 'quoteDynamic'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/revision', [AssistedPurchaseController::class, 'createRevision'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/clarification', [AssistedPurchaseController::class, 'sendClarification'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/report-item-unavailable', [AssistedPurchaseController::class, 'reportItemUnavailable'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('assisted-purchases/{assisted_purchase}/report-price-change', [AssistedPurchaseController::class, 'reportPriceChange'])
        ->middleware('permission:manage_assisted_purchases');

    // --- Quote Line Templates (Settings) ---
    Route::get('quote-line-templates', [QuoteLineTemplateController::class, 'index'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('quote-line-templates/active', [QuoteLineTemplateController::class, 'active'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('quote-line-templates', [QuoteLineTemplateController::class, 'store'])
        ->middleware('permission:manage_assisted_purchases');
    Route::put('quote-line-templates/{quoteLineTemplate}', [QuoteLineTemplateController::class, 'update'])
        ->middleware('permission:manage_assisted_purchases');
    Route::delete('quote-line-templates/{quoteLineTemplate}', [QuoteLineTemplateController::class, 'destroy'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('quote-line-templates/reorder', [QuoteLineTemplateController::class, 'reorder'])
        ->middleware('permission:manage_assisted_purchases');

    // --- Quote Templates (Settings) ---
    Route::get('quote-templates', [QuoteTemplateController::class, 'index'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('quote-templates', [QuoteTemplateController::class, 'store'])
        ->middleware('permission:manage_assisted_purchases');
    Route::put('quote-templates/{quoteTemplate}', [QuoteTemplateController::class, 'update'])
        ->middleware('permission:manage_assisted_purchases');
    Route::delete('quote-templates/{quoteTemplate}', [QuoteTemplateController::class, 'destroy'])
        ->middleware('permission:manage_assisted_purchases');

    // --- Quote Settings (currency, follow-up, email templates, audit) ---
    Route::get('settings/quote-currency', [QuoteSettingsController::class, 'currency'])
        ->middleware('permission:manage_assisted_purchases');
    Route::put('settings/quote-currency', [QuoteSettingsController::class, 'updateCurrency'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('settings/quote-follow-up', [QuoteSettingsController::class, 'followUp'])
        ->middleware('permission:manage_assisted_purchases');
    Route::put('settings/quote-follow-up', [QuoteSettingsController::class, 'updateFollowUp'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('settings/quote-email-templates', [QuoteSettingsController::class, 'emailTemplates'])
        ->middleware('permission:manage_assisted_purchases');
    Route::patch('settings/quote-email-templates/{quoteEmailTemplate}', [QuoteSettingsController::class, 'updateEmailTemplate'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('settings/quote-email-templates/{quoteEmailTemplate}/preview', [QuoteSettingsController::class, 'previewEmailTemplate'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('settings/quote-templates/audit-log', [QuoteSettingsController::class, 'auditLog'])
        ->middleware('permission:manage_assisted_purchases');

    // --- Quote Dashboard ---
    Route::get('quotes/dashboard/metrics', [QuoteDashboardController::class, 'metrics'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('quotes/dashboard/list', [QuoteDashboardController::class, 'list'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('quotes/{assistedPurchase}/prolong', [QuoteDashboardController::class, 'prolong'])
        ->middleware('permission:manage_assisted_purchases');
    Route::post('quotes/{assistedPurchase}/cancel-reminders', [QuoteDashboardController::class, 'cancelReminders'])
        ->middleware('permission:manage_assisted_purchases');

    // --- Analytics Achat Assisté ---
    Route::get('analytics/assisted-purchase', AssistedPurchaseAnalyticsController::class)
        ->middleware('permission:manage_assisted_purchases');

    // --- Shipments ---
    Route::get('shipments', [ShipmentController::class, 'index']);
    Route::get('shipments/create', [ShipmentController::class, 'create']);
    Route::get('shipments/assignable-drivers', [ShipmentController::class, 'assignableDrivers']);
    Route::post('shipments', [ShipmentController::class, 'store']);
    Route::patch('shipments/{shipment}', [ShipmentController::class, 'update']);
    Route::post('shipments/{shipment}/duplicate', [ShipmentController::class, 'duplicate']);
    Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::post('shipments/preview-quote', [ShipmentController::class, 'previewQuote']);
    Route::post('shipments/{shipment}/assign-driver', [ShipmentController::class, 'assignDriver']);
    Route::post('shipments/{shipment}/accept', [ShipmentController::class, 'accept']);
    Route::post('shipments/{shipment}/update-status', [ShipmentController::class, 'updateStatus']);
    Route::post('shipments/{shipment}/deliver', [ShipmentController::class, 'deliver']);
    Route::post('shipments/{shipment}/archive-signed-form', [ShipmentController::class, 'archiveSignedForm']);
    Route::post('shipments/{shipment}/record-payment', [ShipmentController::class, 'recordPayment']);
    Route::patch('shipments/{shipment}/invoice-options', [ShipmentController::class, 'updateInvoiceOptions']);

    // --- Shipment Wizard helpers ---
    Route::prefix('shipment-wizard')->group(function () {
        Route::get('search-profiles', [ShipmentWizardController::class, 'searchProfiles']);
        Route::get('search-clients', [ShipmentWizardController::class, 'searchClients']);
        Route::get('search-recipients', [ShipmentWizardController::class, 'searchRecipients']);
        Route::post('quick-create-client', [ShipmentWizardController::class, 'quickCreateClient']);
        Route::post('quick-create-portal', [ShipmentWizardController::class, 'quickCreatePortal']);
        Route::post('quick-create-recipient', [ShipmentWizardController::class, 'quickCreateRecipient']);
        Route::get('agencies', [ShipmentWizardController::class, 'agencies']);
        Route::get('ship-lines-for-route', [ShipmentWizardController::class, 'shipLinesForRoute']);
        Route::get('client-name/{id}', [ShipmentWizardController::class, 'clientName']);
    });

    // --- Locations ---
    Route::prefix('locations')->group(function () {
        Route::get('phone-countries', [LocationCascadeController::class, 'phoneCountries']);
        Route::get('countries', [LocationCascadeController::class, 'countries']);
        Route::get('countries/{country}/states', [LocationCascadeController::class, 'states']);
        Route::get('states/{state}/cities', [LocationCascadeController::class, 'cities']);
    });

    // --- Regroupements (| = OU Spatie : compat. noms legacy view/create/manage_consolidations)
    Route::get('regroupements', [RegroupementController::class, 'index'])->middleware('permission:view_regroupements|view_consolidations|manage_regroupements|manage_consolidations');
    Route::get('regroupements/suggestions', [RegroupementController::class, 'suggestions'])->middleware('permission:create_regroupements|manage_regroupements');
    Route::post('regroupements', [RegroupementController::class, 'store'])->middleware('permission:create_regroupements|create_consolidations|manage_regroupements|manage_consolidations');
    Route::post('regroupements/{regroupement}/shipments', [RegroupementController::class, 'attachShipment'])->middleware('permission:create_regroupements|create_consolidations|manage_regroupements|manage_consolidations');
    Route::post('regroupements/{regroupement}/attach-shipments', [RegroupementController::class, 'attachShipments'])->middleware('permission:create_regroupements|create_consolidations|manage_regroupements|manage_consolidations');
    Route::patch('regroupements/{regroupement}/status', [RegroupementController::class, 'updateStatus'])->middleware('permission:view_regroupements|view_consolidations|manage_regroupements|manage_consolidations');

    // --- Pickups ---
    Route::get('pickups', [PickupController::class, 'index']);
    Route::post('pickups', [PickupController::class, 'store']);
    Route::post('pickups/{pickup}/assign', [PickupController::class, 'assign'])->middleware('permission:assign_drivers');
    Route::post('pickups/{pickup}/update-status', [PickupController::class, 'updateStatus']);
    Route::post('pickups/{pickup}/completion-photo', [PickupController::class, 'uploadCompletionPhoto']);
    Route::get('pickup-failure-reasons', [PickupFailureReasonController::class, 'indexForOperations']);

    // --- Comments ---
    Route::get('comments', [\App\Http\Controllers\CommentController::class, 'index']);
    Route::post('comments', [\App\Http\Controllers\CommentController::class, 'store']);
    Route::delete('comments/{comment}', [\App\Http\Controllers\CommentController::class, 'destroy']);

    // --- Devises (conversion indicative, lecture taux en base) ---
    Route::post('currency/convert', [\App\Http\Controllers\CurrencyController::class, 'convert']);

    /** Reprise file hors-ligne (PRD ERR-01) */
    Route::post('sync/offline-queue', [\App\Http\Controllers\OfflineSyncController::class, 'processQueue']);

    // --- Refunds ---
    Route::get('refunds', [RefundController::class, 'index']);
    Route::get('refunds/export', [RefundController::class, 'export']);
    Route::post('refunds', [RefundController::class, 'store']);
    Route::get('refunds/{refund}', [RefundController::class, 'show']);
    Route::get('refunds/{refund}/request-proof', [RefundController::class, 'downloadRequestProof']);
    Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('permission:approve_refunds');
    Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->middleware('permission:approve_refunds');
    Route::post('refunds/{refund}/process', [RefundController::class, 'process'])->middleware('permission:manage_refunds');
    Route::post('refunds/{refund}/complete', [RefundController::class, 'complete'])->middleware('permission:manage_refunds');

    // --- Finance ---
    Route::get('finance/dashboard', FinanceDashboardController::class)->middleware('permission:manage_finances');
    Route::get('finance/invoices', [InvoiceController::class, 'index']);
    Route::post('finance/invoices', [InvoiceController::class, 'store'])->middleware('permission:manage_finances');
    Route::get('finance/billing-extras', [InvoiceController::class, 'billingExtrasCatalog'])->middleware('permission:manage_finances');
    Route::post('finance/billing-extras', [InvoiceController::class, 'storeBillingExtra'])->middleware('permission:manage_finances');
    Route::get('finance/payment-proofs', [PaymentProofController::class, 'index']);
    Route::post('finance/payment-proofs', [PaymentProofController::class, 'store']);
    Route::post('finance/payment-proofs/{payment_proof}/approve', [PaymentProofController::class, 'approve'])->middleware('permission:approve_payments');
    Route::post('finance/payment-proofs/{payment_proof}/reject', [PaymentProofController::class, 'reject'])->middleware('permission:approve_payments');
    Route::get('finance/ledger', [LedgerController::class, 'index'])->middleware('permission:manage_finances');
    Route::get('finance/ledger/export', [LedgerController::class, 'export'])->middleware('permission:manage_finances');

    // --- Reports ---
    Route::prefix('reports')->name('reports.')->middleware('permission:view_reports')->group(function () {
        Route::get('/', [ReportController::class, 'summary']);
        Route::get('shipments', [ReportController::class, 'shipments']);
        Route::get('pickups', [ReportController::class, 'pickups']);
        Route::get('regroupements', [ReportController::class, 'regroupements']);
        Route::get('packages', [ReportController::class, 'packages']);
        Route::get('finance', [ReportController::class, 'finance']);
        Route::get('export/shipments', [ReportController::class, 'exportShipments']);
        Route::get('export/pickups', [ReportController::class, 'exportPickups']);
        Route::get('export/finance', [ReportController::class, 'exportFinance']);
        Route::get('summary/pdf', [ReportController::class, 'summaryPdf']);
    });

    // --- Clients (Profile-based) ---
    Route::middleware('permission:manage_clients')->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/{client}', [ClientController::class, 'show']);
        Route::get('clients/{client}/activity', [ClientController::class, 'activity']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::patch('clients/{client}', [ClientController::class, 'update']);
        Route::post('clients/{client}/toggle-active', [ClientController::class, 'toggleActive']);
        Route::post('clients/{client}/create-portal', [ClientController::class, 'createPortal']);
        Route::post('clients/check-duplicates', [ClientController::class, 'checkDuplicates']);
    });

    // --- Address Book (replaces Recipients) ---
    Route::apiResource('address-book', AddressBookController::class)
        ->parameters(['address-book' => 'addressBook']);
    Route::post('address-book/{addressBook}/set-default', [AddressBookController::class, 'setDefault']);

    // --- Users ---
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('users', [UserManagementController::class, 'index']);
        Route::post('users', [UserManagementController::class, 'store']);
        Route::patch('users/{user}', [UserManagementController::class, 'update']);
        Route::post('users/{user}/toggle-active', [UserManagementController::class, 'toggleActive']);
    });

    // --- Drivers ---
    Route::middleware('permission:manage_drivers')->group(function () {
        Route::get('drivers', [DriverController::class, 'index']);
        Route::post('drivers', [DriverController::class, 'store']);
        Route::patch('drivers/{driver}', [DriverController::class, 'update']);
        Route::post('drivers/{driver}/toggle-active', [DriverController::class, 'toggleActive']);
    });

    // --- Notifications ---
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::get('notifications/audit-log', [NotificationController::class, 'auditLog']);

    // --- Newsletter (admin) ---
    Route::middleware('permission:manage_newsletter')->group(function () {
        Route::get('newsletter/subscribers', [NewsletterController::class, 'index']);
        Route::delete('newsletter/subscribers/{subscriber}', [NewsletterController::class, 'destroy']);
    });

    // --- PDF ---
    Route::get('shipments/{shipment}/pdf/invoice', [PdfController::class, 'shipmentInvoice']);
    Route::get('shipments/{shipment}/pdf/label', [PdfController::class, 'shipmentLabel']);
    Route::get('shipments/{shipment}/pdf/form', [PdfController::class, 'shipmentForm']);
    Route::get('shipments/{shipment}/preview/invoice', [PdfController::class, 'previewShipmentInvoice']);
    Route::get('shipments/{shipment}/preview/label', [PdfController::class, 'previewShipmentLabel']);
    Route::get('shipments/{shipment}/preview/form', [PdfController::class, 'previewShipmentForm']);
    Route::get('shipments/{shipment}/pdf/tracking', [PdfController::class, 'trackingReport']);
    Route::get('shipments/{shipment}/pdf/delivery-note', [PdfController::class, 'deliveryNote']);
    Route::get('pickups/{pickup}/pdf/delivery-note', [PdfController::class, 'deliveryNotePickup']);
    Route::get('packages/{preAlert}/pdf/invoice', [PdfController::class, 'packageInvoice']);
    Route::get('assisted-purchases/{assisted_purchase}/pdf/quote', [PdfController::class, 'assistedPurchaseQuote'])
        ->middleware('permission:manage_assisted_purchases');
    Route::get('assisted-purchases/{assisted_purchase}/preview/quote', [PdfController::class, 'previewAssistedPurchaseQuote'])
        ->middleware('permission:manage_assisted_purchases');

    // --- FlexPay phase 2 ---
    Route::prefix('flexpay')->group(function () {
        Route::post('initiate', [\App\Http\Controllers\FlexPayController::class, 'initiatePayment']);
        Route::get('check/{orderNumber}', [\App\Http\Controllers\FlexPayController::class, 'checkStatus']);
    });

    // §21.6 — Impression directe réseau
    Route::prefix('print')->group(function () {
        Route::get('status', [\App\Http\Controllers\NetworkPrintController::class, 'status']);
        Route::post('shipments/{shipment}/label', [\App\Http\Controllers\NetworkPrintController::class, 'printShipmentLabel']);
    });

    // §10.5 — Rapport économies regroupement
    Route::get('regroupements/savings-report', [RegroupementController::class, 'savingsReport']);

    // §19 — Analytics : taux de conversion des devis achat assisté
    Route::get('analytics/quote-conversion', [\App\Http\Controllers\AnalyticsController::class, 'quoteConversion'])
        ->middleware('permission:view_reports');
    Route::middleware('permission:manage_backups')->group(function () {
        Route::get('backup/download', [BackupController::class, 'download']);
        Route::get('backup/tables', [BackupController::class, 'tables']);
        Route::post('backup/selective', [BackupController::class, 'downloadSelective']);
    });

    // --- Settings ---
    Route::prefix('settings')->group(function () {
        Route::get('/', SettingsHubController::class)->middleware('permission:manage_settings|manage_pricing|manage_agencies|manage_notifications|manage_statuses');

        // System-level settings: super_admin only (manage_settings)
        Route::middleware('permission:manage_settings')->group(function () {
            Route::get('app', [AppSettingController::class, 'edit']);
            Route::put('app', [AppSettingController::class, 'update']);
            Route::post('app/logo', [AppSettingController::class, 'uploadLogo']);
            Route::post('app/favicon', [AppSettingController::class, 'uploadFavicon']);

            Route::get('payment-methods', [PaymentMethodController::class, 'index']);
            Route::post('payment-methods', [PaymentMethodController::class, 'store']);
            Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

            Route::get('payment-gateways', [PaymentGatewayController::class, 'index']);
            Route::put('payment-gateways', [PaymentGatewayController::class, 'update']);

            Route::get('smtp-config', [SmtpConfigController::class, 'index']);
            Route::put('smtp-config', [SmtpConfigController::class, 'update']);
            Route::post('smtp-config/test', [SmtpConfigController::class, 'test']);

            Route::get('twilio-config', [TwilioConfigController::class, 'index']);
            Route::put('twilio-config', [TwilioConfigController::class, 'update']);
            Route::post('twilio-config/test', [TwilioConfigController::class, 'test']);
        });

        // Operational settings: agency_admin + super_admin
        Route::middleware('permission:manage_agencies')->group(function () {
            Route::get('agencies', [AgencyController::class, 'index']);
            Route::post('agencies', [AgencyController::class, 'store']);
            Route::patch('agencies/{agency}', [AgencyController::class, 'update']);

            Route::get('agency-payment-coordinates', [AgencyPaymentCoordinateController::class, 'index']);
            Route::post('agency-payment-coordinates', [AgencyPaymentCoordinateController::class, 'store']);
            Route::delete('agency-payment-coordinates/{agencyPaymentCoordinate}', [AgencyPaymentCoordinateController::class, 'destroy']);
        });

        Route::middleware('permission:manage_pricing')->group(function () {
            Route::get('pricing-rules', [PricingRuleController::class, 'index']);
            Route::post('pricing-rules', [PricingRuleController::class, 'store']);
            Route::delete('pricing-rules/{pricingRule}', [PricingRuleController::class, 'destroy']);

            Route::get('zones', [ZoneController::class, 'index']);
            Route::post('zones', [ZoneController::class, 'store']);
            Route::delete('zones/{zone}', [ZoneController::class, 'destroy']);

            Route::get('billing-extras', [BillingExtraController::class, 'index']);
            Route::post('billing-extras', [BillingExtraController::class, 'store']);
            Route::patch('billing-extras/{billingExtra}', [BillingExtraController::class, 'update']);
            Route::delete('billing-extras/{billingExtra}', [BillingExtraController::class, 'destroy']);

            Route::get('shipping-modes', [ShippingModeController::class, 'index']);
            Route::post('shipping-modes', [ShippingModeController::class, 'store']);
            Route::patch('shipping-modes/{shippingMode}', [ShippingModeController::class, 'update']);
            Route::delete('shipping-modes/{shippingMode}', [ShippingModeController::class, 'destroy']);

            Route::get('packaging-types', [PackagingTypeController::class, 'index']);
            Route::post('packaging-types', [PackagingTypeController::class, 'store']);
            Route::patch('packaging-types/{packagingType}', [PackagingTypeController::class, 'update']);
            Route::delete('packaging-types/{packagingType}', [PackagingTypeController::class, 'destroy']);

            Route::get('article-categories', [ArticleCategoryController::class, 'index']);
            Route::post('article-categories', [ArticleCategoryController::class, 'store']);
            Route::patch('article-categories/{articleCategory}', [ArticleCategoryController::class, 'update']);
            Route::delete('article-categories/{articleCategory}', [ArticleCategoryController::class, 'destroy']);

            Route::get('transport-companies', [TransportCompanyController::class, 'index']);
            Route::post('transport-companies', [TransportCompanyController::class, 'store']);
            Route::patch('transport-companies/{transportCompany}', [TransportCompanyController::class, 'update']);
            Route::delete('transport-companies/{transportCompany}', [TransportCompanyController::class, 'destroy']);

            Route::get('locations', [LocationController::class, 'index']);
            Route::post('locations/countries', [LocationController::class, 'storeCountry']);
            Route::delete('locations/countries/{country}', [LocationController::class, 'destroyCountry']);
            Route::post('locations/states', [LocationController::class, 'storeState']);
            Route::delete('locations/states/{state}', [LocationController::class, 'destroyState']);
            Route::post('locations/cities', [LocationController::class, 'storeCity']);
            Route::delete('locations/cities/{city}', [LocationController::class, 'destroyCity']);

            Route::get('ship-lines', [ShipLineController::class, 'index']);
            Route::get('ship-lines/for-route', [ShipLineController::class, 'forRoute']);
            Route::post('ship-lines/merge-route', [ShipLineController::class, 'mergeRoute']);
            Route::post('ship-lines', [ShipLineController::class, 'store']);
            Route::patch('ship-lines/{shipLine}', [ShipLineController::class, 'update']);
            Route::delete('ship-lines/{shipLine}', [ShipLineController::class, 'destroy']);

            Route::get('exchange-rates', [\App\Http\Controllers\Settings\ExchangeRateController::class, 'index'])
                ->middleware('permission:manage_exchange_rates');
            Route::post('exchange-rates', [\App\Http\Controllers\Settings\ExchangeRateController::class, 'store'])
                ->middleware('permission:manage_exchange_rates');
        });

        Route::middleware('permission:manage_notifications')->group(function () {
            Route::get('notifications', [NotificationTemplateController::class, 'index']);
            Route::post('notifications', [NotificationTemplateController::class, 'store']);
            Route::patch('notifications/{notificationTemplate}', [NotificationTemplateController::class, 'update']);
        });

        Route::middleware('permission:manage_assisted_purchases')->group(function () {
            Route::get('merchants', [MerchantController::class, 'index']);
            Route::post('merchants', [MerchantController::class, 'store']);
            Route::patch('merchants/{merchant}', [MerchantController::class, 'update']);
            Route::delete('merchants/{merchant}', [MerchantController::class, 'destroy']);
        });

        Route::middleware('permission:manage_settings')->group(function () {
            Route::get('sync-errors', [\App\Http\Controllers\Settings\SyncErrorController::class, 'index']);
            Route::post('sync-errors/{syncError}/retry', [\App\Http\Controllers\Settings\SyncErrorController::class, 'retry']);
            Route::post('sync-errors/{syncError}/resolve', [\App\Http\Controllers\Settings\SyncErrorController::class, 'resolve']);

            Route::get('pickup-failure-reasons', [PickupFailureReasonController::class, 'index']);
            Route::post('pickup-failure-reasons', [PickupFailureReasonController::class, 'store']);
            Route::patch('pickup-failure-reasons/{pickupFailureReason}', [PickupFailureReasonController::class, 'update']);
            Route::delete('pickup-failure-reasons/{pickupFailureReason}', [PickupFailureReasonController::class, 'destroy']);
        });

        Route::middleware('permission:manage_roles')->group(function () {
            Route::get('roles-permissions', [RolePermissionController::class, 'index']);
            Route::put('roles/{role}', [RolePermissionController::class, 'updateRole']);
            Route::post('roles', [RolePermissionController::class, 'storeRole']);
            Route::delete('roles/{role}', [RolePermissionController::class, 'destroyRole']);
            Route::post('permissions', [RolePermissionController::class, 'storePermission']);
            Route::get('users/{user}/permissions', [RolePermissionController::class, 'userPermissions']);
            Route::put('users/{user}/permissions', [RolePermissionController::class, 'updateUserPermissions']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Public routes (no auth)
|--------------------------------------------------------------------------
*/
Route::get('track/{trackingNumber}', [\App\Http\Controllers\TrackingController::class, 'apiTrack']);
Route::post('track-events', [\App\Http\Controllers\TrackEventController::class, 'store'])->middleware('throttle:120,1');
Route::post('webhooks/flexpay', [\App\Http\Controllers\WebhookController::class, 'flexpay']);

// §17 — Widget WordPress : endpoint public de suivi embeddable
Route::get('widget/track/{trackingNumber}', [\App\Http\Controllers\TrackingController::class, 'widgetTrack']);

// §17 — Formulaires WordPress publics (achat assisté + pré-alerte)
Route::middleware('throttle:30,1')->group(function () {
    Route::post('widget/assisted-purchase', [\App\Http\Controllers\WordPressFormController::class, 'createAssistedPurchase']);
    Route::post('widget/pre-alert', [\App\Http\Controllers\WordPressFormController::class, 'createPreAlert']);
});

// --- Quote Response (public, signed link) ---
Route::get('quotes/verify-token', [QuoteResponseController::class, 'verifyToken']);
Route::post('quotes/respond', [QuoteResponseController::class, 'respond']);

// --- Assisted Purchase Public Form ---
Route::middleware('throttle:10,1')->group(function () {
    Route::post('assisted-purchases/public', [AssistedPurchasePublicController::class, 'store']);
});

// --- Inbound Email Webhook (achats@monrespro.cd) ---
Route::post('webhooks/inbound-email', [InboundEmailController::class, 'handle']);

Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe']);
Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe']);

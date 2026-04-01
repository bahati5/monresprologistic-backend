<?php

use App\Http\Controllers\AddressBookController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LocationCascadeController;
use App\Http\Controllers\Api\ShipmentWizardController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ConsolidationController;
use App\Http\Controllers\CustomerPackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FinanceDashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LockerController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PickupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SectionDashboardController;
use App\Http\Controllers\Settings\AgencyController;
use App\Http\Controllers\Settings\AgencyPaymentCoordinateController;
use App\Http\Controllers\Settings\AppSettingController;
use App\Http\Controllers\Settings\ArticleCategoryController;
use App\Http\Controllers\Settings\DocumentTemplateController;
use App\Http\Controllers\Settings\LocationController;
use App\Http\Controllers\Settings\NotificationTemplateController;
use App\Http\Controllers\Settings\OfficeController;
use App\Http\Controllers\Settings\PackagingTypeController;
use App\Http\Controllers\Settings\PaymentGatewayController;
use App\Http\Controllers\Settings\PaymentMethodController;
use App\Http\Controllers\Settings\PricingRuleController;
use App\Http\Controllers\Settings\SettingsHubController;
use App\Http\Controllers\Settings\ShipLineController;
use App\Http\Controllers\Settings\ShippingModeController;
use App\Http\Controllers\Settings\ShippingRateController;
use App\Http\Controllers\Settings\SmtpConfigController;
use App\Http\Controllers\Settings\StatusController;
use App\Http\Controllers\Settings\TaxController;
use App\Http\Controllers\Settings\TransportCompanyController;
use App\Http\Controllers\Settings\TwilioConfigController;
use App\Http\Controllers\Settings\WorkflowController;
use App\Http\Controllers\Settings\ZoneController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentNoticeController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
*/
Route::get('/ping', fn () => response()->json(['status' => 'ok']));

/*
|--------------------------------------------------------------------------
| Authentication (public)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

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
    Route::get('dashboard/consolidations', [SectionDashboardController::class, 'consolidations']);
    Route::get('dashboard/crm', [SectionDashboardController::class, 'crm']);
    Route::get('dashboard/reports', [SectionDashboardController::class, 'reports']);

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

    // --- Purchase Orders ---
    Route::apiResource('purchase-orders', PurchaseOrderController::class)
        ->parameters(['purchase-orders' => 'purchaseOrder:id']);
    Route::post('purchase-orders/{purchaseOrder}/quote', [PurchaseOrderController::class, 'quote']);
    Route::post('purchase-orders/{purchaseOrder}/mark-paid', [PurchaseOrderController::class, 'markPaid']);
    Route::post('purchase-orders/{purchaseOrder}/mark-purchased', [PurchaseOrderController::class, 'markPurchased']);
    Route::post('purchase-orders/{purchaseOrder}/convert', [PurchaseOrderController::class, 'convert']);

    // --- Shipments ---
    Route::get('shipments', [ShipmentController::class, 'index']);
    Route::get('shipments/create', [ShipmentController::class, 'create']);
    Route::get('shipments/assignable-drivers', [ShipmentController::class, 'assignableDrivers']);
    Route::post('shipments', [ShipmentController::class, 'store']);
    Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::post('shipments/preview-quote', [ShipmentController::class, 'previewQuote']);
    Route::post('shipments/{shipment}/assign-driver', [ShipmentController::class, 'assignDriver']);
    Route::get('shipments/{shipment}/acceptance', [ShipmentController::class, 'acceptance']);
    Route::post('shipments/{shipment}/accept', [ShipmentController::class, 'accept']);
    Route::post('shipments/{shipment}/update-status', [ShipmentController::class, 'updateStatus']);
    Route::post('shipments/{shipment}/deliver', [ShipmentController::class, 'deliver']);
    Route::post('shipments/{shipment}/record-payment', [ShipmentController::class, 'recordPayment']);

    // --- Shipment Wizard helpers ---
    Route::prefix('shipment-wizard')->group(function () {
        Route::get('search-profiles', [ShipmentWizardController::class, 'searchProfiles']);
        Route::get('search-clients', [ShipmentWizardController::class, 'searchClients']);
        Route::get('search-recipients', [ShipmentWizardController::class, 'searchRecipients']);
        Route::post('quick-create-client', [ShipmentWizardController::class, 'quickCreateClient']);
        Route::post('quick-create-recipient', [ShipmentWizardController::class, 'quickCreateRecipient']);
        Route::post('quick-create-delivery-time', [ShipmentWizardController::class, 'quickCreateDeliveryTime']);
        Route::get('agencies', [ShipmentWizardController::class, 'agencies']);
        Route::get('ship-lines-for-route', [ShipmentWizardController::class, 'shipLinesForRoute']);
    });

    // --- Locations ---
    Route::prefix('locations')->group(function () {
        Route::get('phone-countries', [LocationCascadeController::class, 'phoneCountries']);
        Route::get('timezones', [LocationCascadeController::class, 'timezones']);
        Route::get('countries', [LocationCascadeController::class, 'countries']);
        Route::get('countries/{country}/states', [LocationCascadeController::class, 'states']);
        Route::get('states/{state}/cities', [LocationCascadeController::class, 'cities']);
    });

    // --- Consolidations ---
    Route::get('consolidations', [ConsolidationController::class, 'index'])->middleware('permission:view_consolidations');
    Route::post('consolidations', [ConsolidationController::class, 'store'])->middleware('permission:create_consolidations');
    Route::patch('consolidations/{consolidation}/status', [ConsolidationController::class, 'updateStatus'])->middleware('permission:view_consolidations');

    // --- Pickups ---
    Route::get('pickups', [PickupController::class, 'index']);
    Route::post('pickups', [PickupController::class, 'store']);
    Route::post('pickups/{pickup}/assign', [PickupController::class, 'assign'])->middleware('permission:assign_drivers');
    Route::post('pickups/{pickup}/update-status', [PickupController::class, 'updateStatus']);

    // --- Finance ---
    Route::get('finance/dashboard', FinanceDashboardController::class)->middleware('permission:manage_finances');
    Route::get('finance/invoices', [InvoiceController::class, 'index']);
    Route::post('finance/invoices', [InvoiceController::class, 'store'])->middleware('permission:manage_finances');
    Route::get('finance/payment-proofs', [PaymentProofController::class, 'index']);
    Route::post('finance/payment-proofs', [PaymentProofController::class, 'store']);
    Route::post('finance/payment-proofs/{payment_proof}/approve', [PaymentProofController::class, 'approve'])->middleware('permission:approve_payments');
    Route::post('finance/payment-proofs/{payment_proof}/reject', [PaymentProofController::class, 'reject'])->middleware('permission:approve_payments');
    Route::get('finance/ledger', LedgerController::class)->middleware('permission:manage_finances');
    Route::get('finance/wallets', [WalletController::class, 'index']);
    Route::post('finance/wallets/deposit', [WalletController::class, 'deposit'])->middleware('permission:manage_finances');

    // --- Reports ---
    Route::prefix('reports')->name('reports.')->middleware('permission:view_reports')->group(function () {
        Route::get('/', [ReportController::class, 'summary']);
        Route::get('shipments', [ReportController::class, 'shipments']);
        Route::get('pickups', [ReportController::class, 'pickups']);
        Route::get('consolidations', [ReportController::class, 'consolidations']);
        Route::get('packages', [ReportController::class, 'packages']);
        Route::get('finance', [ReportController::class, 'finance']);
        Route::get('export/shipments', [ReportController::class, 'exportShipments']);
        Route::get('export/pickups', [ReportController::class, 'exportPickups']);
        Route::get('export/finance', [ReportController::class, 'exportFinance']);
    });

    // --- Clients (Profile-based) ---
    Route::middleware('permission:manage_clients')->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/{client}', [ClientController::class, 'show']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::patch('clients/{client}', [ClientController::class, 'update']);
        Route::post('clients/{client}/toggle-active', [ClientController::class, 'toggleActive']);
        Route::post('clients/{client}/create-portal', [ClientController::class, 'createPortal']);
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

    // --- Newsletter (admin) ---
    Route::middleware('permission:manage_newsletter')->group(function () {
        Route::get('newsletter/subscribers', [NewsletterController::class, 'index']);
        Route::delete('newsletter/subscribers/{subscriber}', [NewsletterController::class, 'destroy']);
    });

    // --- PDF ---
    Route::get('shipments/{shipment}/pdf/invoice', [PdfController::class, 'shipmentInvoice']);
    Route::get('shipments/{shipment}/pdf/label', [PdfController::class, 'shipmentLabel']);
    Route::get('shipments/{shipment}/preview/invoice', [PdfController::class, 'previewShipmentInvoice']);
    Route::get('shipments/{shipment}/preview/label', [PdfController::class, 'previewShipmentLabel']);
    Route::get('shipments/{shipment}/pdf/tracking', [PdfController::class, 'trackingReport']);
    Route::get('packages/{preAlert}/pdf/invoice', [PdfController::class, 'packageInvoice']);

    // --- Backup ---
    Route::middleware('permission:manage_backups')->group(function () {
        Route::get('backup/download', [BackupController::class, 'download']);
        Route::get('backup/tables', [BackupController::class, 'tables']);
        Route::post('backup/selective', [BackupController::class, 'downloadSelective']);
    });

    // --- Settings ---
    Route::prefix('settings')->middleware('permission:manage_settings')->group(function () {
        Route::get('/', SettingsHubController::class);

        Route::get('app', [AppSettingController::class, 'edit']);
        Route::put('app', [AppSettingController::class, 'update']);
        Route::post('app/logo', [AppSettingController::class, 'uploadLogo']);
        Route::post('app/favicon', [AppSettingController::class, 'uploadFavicon']);

        Route::middleware('permission:manage_agencies')->group(function () {
            Route::get('agencies', [AgencyController::class, 'index']);
            Route::post('agencies', [AgencyController::class, 'store']);
            Route::patch('agencies/{agency}', [AgencyController::class, 'update']);
        });

        Route::middleware('permission:manage_statuses')->group(function () {
            Route::get('statuses', [StatusController::class, 'index']);
            Route::post('statuses', [StatusController::class, 'store']);
            Route::patch('statuses/{status}', [StatusController::class, 'update']);
            Route::delete('statuses/{status}', [StatusController::class, 'destroy']);

            Route::get('workflows', [WorkflowController::class, 'index']);
            Route::post('workflows', [WorkflowController::class, 'store']);
            Route::delete('workflows/{status_transition}', [WorkflowController::class, 'destroy']);
        });

        Route::middleware('permission:manage_pricing')->group(function () {
            Route::get('pricing-rules', [PricingRuleController::class, 'index']);
            Route::post('pricing-rules', [PricingRuleController::class, 'store']);
            Route::delete('pricing-rules/{pricingRule}', [PricingRuleController::class, 'destroy']);

            Route::get('zones', [ZoneController::class, 'index']);
            Route::post('zones', [ZoneController::class, 'store']);
            Route::delete('zones/{zone}', [ZoneController::class, 'destroy']);
        });

        Route::middleware('permission:manage_notifications')->group(function () {
            Route::get('notifications', [NotificationTemplateController::class, 'index']);
            Route::post('notifications', [NotificationTemplateController::class, 'store']);
            Route::patch('notifications/{notificationTemplate}', [NotificationTemplateController::class, 'update']);
        });

        Route::get('payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('payment-methods', [PaymentMethodController::class, 'store']);
        Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

        Route::get('taxes', [TaxController::class, 'index']);
        Route::post('taxes', [TaxController::class, 'store']);
        Route::delete('taxes/{tax}', [TaxController::class, 'destroy']);

        Route::get('shipping-rates', [ShippingRateController::class, 'index']);
        Route::post('shipping-rates', [ShippingRateController::class, 'store']);
        Route::patch('shipping-rates/{shippingRate}', [ShippingRateController::class, 'update']);
        Route::delete('shipping-rates/{shippingRate}', [ShippingRateController::class, 'destroy']);

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

        Route::get('offices', [OfficeController::class, 'index']);
        Route::post('offices', [OfficeController::class, 'store']);
        Route::patch('offices/{office}', [OfficeController::class, 'update']);
        Route::delete('offices/{office}', [OfficeController::class, 'destroy']);

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

        Route::get('payment-gateways', [PaymentGatewayController::class, 'index']);
        Route::put('payment-gateways', [PaymentGatewayController::class, 'update']);

        Route::get('agency-payment-coordinates', [AgencyPaymentCoordinateController::class, 'index']);
        Route::post('agency-payment-coordinates', [AgencyPaymentCoordinateController::class, 'store']);
        Route::delete('agency-payment-coordinates/{agencyPaymentCoordinate}', [AgencyPaymentCoordinateController::class, 'destroy']);

        Route::get('smtp-config', [SmtpConfigController::class, 'index']);
        Route::put('smtp-config', [SmtpConfigController::class, 'update']);

        Route::get('twilio-config', [TwilioConfigController::class, 'index']);
        Route::put('twilio-config', [TwilioConfigController::class, 'update']);

        Route::get('document-templates', [DocumentTemplateController::class, 'index']);
        Route::put('document-templates', [DocumentTemplateController::class, 'update']);
    });
});

/*
|--------------------------------------------------------------------------
| Public routes (no auth)
|--------------------------------------------------------------------------
*/
Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe']);
Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe']);

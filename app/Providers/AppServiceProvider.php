<?php

namespace App\Providers;

use App\Events\AssistedPurchaseStatusChanged;
use App\Events\PickupStatusChanged;
use App\Events\RefundStatusChanged;
use App\Events\ShipmentStatusChanged;
use App\Listeners\CreateInvoiceOnShipmentDelivered;
use App\Listeners\CreateRefundOnAssistedPurchaseFailed;
use App\Listeners\NotifyClientOnStatusChange;
use App\Listeners\NotifyStaffOnClientRefundRequest;
use App\Listeners\SyncFreshsalesListener;
use App\Listeners\SyncOdooListener;
use App\Listeners\ProvisionRegisteredUser;
use App\Models\Invoice;
use App\Models\Shipment;
use App\Observers\InvoiceObserver;
use App\Observers\ShipmentObserver;
use App\Support\DynamicMailSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ne pas plafonner le temps en CLI : `artisan serve`, `queue:work`, etc. tournent indéfiniment.
        if ($this->app->environment('local') && ! $this->app->runningInConsole()) {
            $limit = (int) env('APP_MAX_EXECUTION_TIME', 120);
            if ($limit > 0) {
                @ini_set('max_execution_time', (string) $limit);
                @set_time_limit($limit);
            }
        }

        Vite::prefetch(concurrency: 3);

        Event::listen(Registered::class, ProvisionRegisteredUser::class);
        Shipment::observe(ShipmentObserver::class);
        Invoice::observe(InvoiceObserver::class);

        Event::listen(ShipmentStatusChanged::class, [NotifyClientOnStatusChange::class, 'handleShipmentStatusChanged']);
        Event::listen(AssistedPurchaseStatusChanged::class, [NotifyClientOnStatusChange::class, 'handleAssistedPurchaseStatusChanged']);
        Event::listen(PickupStatusChanged::class, [NotifyClientOnStatusChange::class, 'handlePickupStatusChanged']);
        Event::listen(RefundStatusChanged::class, [NotifyClientOnStatusChange::class, 'handleRefundStatusChanged']);
        Event::listen(RefundStatusChanged::class, [NotifyStaffOnClientRefundRequest::class, 'handle']);

        // §5.5 — Remboursement automatique si achat assisté passe en FAILED
        Event::listen(AssistedPurchaseStatusChanged::class, [CreateRefundOnAssistedPurchaseFailed::class, 'handle']);

        Event::listen(ShipmentStatusChanged::class, [SyncFreshsalesListener::class, 'handleShipmentStatusChanged']);
        Event::listen(AssistedPurchaseStatusChanged::class, [SyncFreshsalesListener::class, 'handleAssistedPurchaseStatusChanged']);
        Event::listen(RefundStatusChanged::class, [SyncFreshsalesListener::class, 'handleRefundStatusChanged']);
        // §15.1 — Sync contact Freshsales dès l'inscription d'un client
        Event::listen(Registered::class, [SyncFreshsalesListener::class, 'handleRegistered']);

        // §9.8 — Facture automatique à la livraison (avant sync Odoo qui consomme la facture)
        Event::listen(ShipmentStatusChanged::class, [CreateInvoiceOnShipmentDelivered::class, 'handle']);

        // §16 — Synchronisation Odoo ERP
        Event::listen(RefundStatusChanged::class, [SyncOdooListener::class, 'handleRefundStatusChanged']);
        Event::listen(ShipmentStatusChanged::class, [SyncOdooListener::class, 'handleShipmentStatusChanged']);

        DynamicMailSettings::applyFromDatabaseIfConfigured();
    }
}

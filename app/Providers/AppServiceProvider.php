<?php

namespace App\Providers;

use App\Listeners\ProvisionRegisteredUser;
use App\Models\Shipment;
use App\Observers\ShipmentObserver;
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
        Vite::prefetch(concurrency: 3);

        Event::listen(Registered::class, ProvisionRegisteredUser::class);
        Shipment::observe(ShipmentObserver::class);
    }
}

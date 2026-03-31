<?php

namespace App\Observers;

use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Support\Str;

class ShipmentObserver
{
    public function creating(Shipment $shipment): void
    {
        if ($shipment->public_tracking) {
            return;
        }

        $prefix = Setting::getValue('tracking_prefix', 'MRP');
        $length = (int) (Setting::getValue('tracking_number_length', '8') ?: 8);
        $length = max(4, min(32, $length));

        do {
            $code = $prefix.'-'.strtoupper(Str::random($length));
        } while (Shipment::query()->where('public_tracking', $code)->exists());

        $shipment->public_tracking = $code;
    }
}

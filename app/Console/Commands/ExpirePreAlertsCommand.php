<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\PreAlert;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * §7.2 PRD — Pré-alertes non réceptionnées après un délai configurable : annulation (statut cancelled).
 */
class ExpirePreAlertsCommand extends Command
{
    protected $signature = 'pre-alerts:expire';

    protected $description = 'Annule les pré-alertes en attente de dépôt au-delà du délai configuré';

    public function handle(): int
    {
        $days = max(1, (int) Setting::getValue('pre_alert_expiry_days', 90));
        $cutoff = now()->subDays($days);

        $q = PreAlert::query()
            ->where('status', ShipmentStatus::PendingDropOff)
            ->where('created_at', '<', $cutoff)
            ->whereNull('converted_customer_package_id');

        $count = 0;
        foreach ($q->cursor() as $preAlert) {
            if (! $preAlert->status->canTransitionTo(ShipmentStatus::Expired)) {
                continue;
            }
            $preAlert->update(['status' => ShipmentStatus::Expired]);
            $count++;
        }

        $this->info("Pré-alertes expirées (statut expiré) : {$count}");

        return self::SUCCESS;
    }
}

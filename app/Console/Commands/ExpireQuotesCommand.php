<?php

namespace App\Console\Commands;

use App\Enums\AssistedPurchaseStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Models\AssistedPurchase;
use App\Models\Setting;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * §5.6 — Expire les devis non répondus après le délai configuré (défaut 72h).
 * Doit être planifié toutes les heures.
 */
class ExpireQuotesCommand extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Expire les devis d\'achat assisté non répondus après le délai configuré';

    public function handle(): int
    {
        $hours = (int) Setting::getValue('quote_expiry_hours', 72);
        $cutoff = now()->subHours($hours);

        $expired = AssistedPurchase::query()
            ->whereIn('status', [
                AssistedPurchaseStatus::QUOTED->value,
                AssistedPurchaseStatus::AWAITING_PAYMENT->value,
            ])
            ->where('quoted_at', '<=', $cutoff)
            ->get();

        foreach ($expired as $purchase) {
            $old = $purchase->status;
            $purchase->update(['status' => AssistedPurchaseStatus::EXPIRED]);
            AssistedPurchaseStatusChanged::dispatch($purchase->fresh(), $old, AssistedPurchaseStatus::EXPIRED, null);
            $this->info("Expired purchase #{$purchase->id}");
        }

        $this->info("Total expired: {$expired->count()}");

        return self::SUCCESS;
    }
}

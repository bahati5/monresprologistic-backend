<?php

namespace App\Console\Commands;

use App\Enums\AssistedPurchaseStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Models\AssistedPurchase;
use App\Models\QuoteSnapshot;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Expire les devis non répondus après le délai configuré.
 * Utilise quote_expires_at si disponible, sinon fallback sur quote_validity_days depuis quoted_at.
 */
class ExpireQuotesCommand extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Expire les devis d\'achat assisté non répondus après le délai configuré';

    public function handle(): int
    {
        $validityDays = (int) Setting::getValue('quote_validity_days', '7');
        $fallbackCutoff = now()->subDays($validityDays);

        $expired = AssistedPurchase::query()
            ->where('status', AssistedPurchaseStatus::QUOTED->value)
            ->where(function ($query) use ($fallbackCutoff) {
                $query->where(function ($q) {
                    $q->whereNotNull('quote_expires_at')
                        ->where('quote_expires_at', '<=', now());
                })->orWhere(function ($q) use ($fallbackCutoff) {
                    $q->whereNull('quote_expires_at')
                        ->where('quoted_at', '<=', $fallbackCutoff);
                });
            })
            ->get();

        foreach ($expired as $purchase) {
            $old = $purchase->status;
            $purchase->update(['status' => AssistedPurchaseStatus::EXPIRED]);

            $latestSnapshot = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
                ->where('client_response', 'pending')
                ->orderByDesc('version')
                ->first();

            if ($latestSnapshot) {
                $latestSnapshot->update(['client_response' => 'expired']);
            }

            AssistedPurchaseStatusChanged::dispatch(
                $purchase->fresh(['user']),
                $old,
                AssistedPurchaseStatus::EXPIRED,
                null
            );

            $this->info("Expired purchase #{$purchase->id}");
        }

        $this->info("Total expired: {$expired->count()}");

        return self::SUCCESS;
    }
}

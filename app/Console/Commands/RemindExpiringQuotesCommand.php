<?php

namespace App\Console\Commands;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\Setting;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * §5.6 — Envoie un rappel 24h avant l'expiration d'un devis.
 * Doit être planifié toutes les heures.
 */
class RemindExpiringQuotesCommand extends Command
{
    protected $signature = 'quotes:remind-expiring';

    protected $description = 'Envoie un rappel aux clients dont le devis expire dans les prochaines 24h';

    public function handle(): int
    {
        $hours = (int) Setting::getValue('quote_expiry_hours', 72);
        $reminderBefore = 24;

        // Devis qui expirent entre maintenant et dans $reminderBefore heures
        $cutoffFrom = now()->subHours($hours - $reminderBefore);
        $cutoffTo = now()->subHours($hours - $reminderBefore - 1);

        $expiring = AssistedPurchase::query()
            ->whereIn('status', [
                AssistedPurchaseStatus::QUOTED->value,
                AssistedPurchaseStatus::AWAITING_PAYMENT->value,
            ])
            ->whereBetween('quoted_at', [$cutoffTo, $cutoffFrom])
            ->with('user')
            ->get();

        foreach ($expiring as $purchase) {
            $user = $purchase->user;
            if (! $user) {
                continue;
            }

            NotificationDispatcher::dispatch(
                user: $user,
                eventKey: 'assisted_purchase.quote_expiring_soon',
                variables: [
                    'client_nom' => $user->name ?? '',
                    'hours_left' => (string) $reminderBefore,
                ],
                actionUrl: "/purchase-orders/{$purchase->id}",
            );

            // Staff notification (agence du client — AssistedPurchase n'a pas de agency_id)
            $agencyId = $user->agency_id;
            $staffRecipients = \App\Models\User::query()
                ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['agency_admin', 'operator', 'super_admin']))
                ->get();

            foreach ($staffRecipients as $staff) {
                NotificationDispatcher::dispatch(
                    user: $staff,
                    eventKey: 'assisted_purchase.quote_expiring_soon',
                    variables: [
                        'client_nom' => $user->name ?? '',
                        'hours_left' => (string) $reminderBefore,
                    ],
                    actionUrl: "/purchase-orders/{$purchase->id}/chiffrage",
                );
            }

            $this->info("Reminder sent for purchase #{$purchase->id}");
        }

        $this->info("Total reminders: {$expiring->count()}");

        return self::SUCCESS;
    }
}

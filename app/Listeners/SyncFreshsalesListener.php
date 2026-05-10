<?php

namespace App\Listeners;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\ShipmentStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Events\RefundStatusChanged;
use App\Events\ShipmentStatusChanged;
use App\Jobs\SyncToFreshsalesJob;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §15 — Synchronise les événements métier vers Freshsales CRM de façon asynchrone.
 */
class SyncFreshsalesListener implements ShouldQueue
{
    /**
     * §15.1 — Nouveau compte client → créer le contact dans Freshsales dès l'inscription.
     */
    public function handleRegistered(Registered $event): void
    {
        $user = $event->user;

        if (! $user->hasRole('client')) {
            return;
        }

        SyncToFreshsalesJob::dispatch('upsert_contact', [
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'tags'        => $this->buildContactTags($user),
        ]);
    }

    public function handleShipmentStatusChanged(ShipmentStatusChanged $event): void
    {
        $shipment = $event->shipment;

        // Création d'un deal si c'est la première fois qu'on voit cette expédition
        if ($event->newStatus === ShipmentStatus::Draft) {
            SyncToFreshsalesJob::dispatch('create_deal', [
                'entity_type' => 'Shipment',
                'entity_id'   => $shipment->id,
                'name'        => "Expedition {$shipment->public_tracking}",
                'stage'       => $event->newStatus->label(),
                'amount'      => (float) ($shipment->calculated_price ?? 0),
                'contact_id'  => $shipment->creator_user_id,
            ]);

            return;
        }

        // §6.5 — Statut CUSTOMS_HOLD → ouvrir un ticket support dans Freshsales
        if ($event->newStatus === ShipmentStatus::CustomsHold) {
            SyncToFreshsalesJob::dispatch('create_ticket', [
                'entity_type' => 'Shipment',
                'entity_id'   => $shipment->id,
                'subject'     => "Rétention douanière — {$shipment->public_tracking}",
                'description' => "L'expédition {$shipment->public_tracking} est en attente douanière.",
                'contact_id'  => $shipment->creator_user_id,
                'status'      => 'open',
                'priority'    => 'high',
            ]);
        }

        SyncToFreshsalesJob::dispatch('update_deal', [
            'deal_id'     => $shipment->id,
            'entity_type' => 'Shipment',
            'entity_id'   => $shipment->id,
            'name'        => "Expedition {$shipment->public_tracking}",
            'stage'       => $event->newStatus->label(),
        ]);

        // Expédition livrée → fermer deal + tâche satisfaction J+2
        if ($event->newStatus === ShipmentStatus::Delivered) {
            SyncToFreshsalesJob::dispatch('close_deal_won', [
                'entity_type'          => 'Shipment',
                'entity_id'            => $shipment->id,
                'create_followup_task' => true,
            ]);
        }
    }

    public function handleAssistedPurchaseStatusChanged(AssistedPurchaseStatusChanged $event): void
    {
        $purchase = $event->purchase;

        if ($event->newStatus === AssistedPurchaseStatus::PENDING_QUOTE) {
            SyncToFreshsalesJob::dispatch('create_deal', [
                'entity_type' => 'AssistedPurchase',
                'entity_id'   => $purchase->id,
                'name'        => "Achat assiste #{$purchase->id}",
                'stage'       => $event->newStatus->label(),
                'contact_id'  => $purchase->user_id,
            ]);

            if ($purchase->user) {
                SyncToFreshsalesJob::dispatch('upsert_contact', [
                    'entity_type' => 'User',
                    'entity_id'   => $purchase->user_id,
                    'name'        => $purchase->user->name,
                    'email'       => $purchase->user->email,
                    'phone'       => $purchase->user->phone,
                    'tags'        => $this->buildContactTags($purchase->user),
                ]);
            }

            return;
        }

        SyncToFreshsalesJob::dispatch('update_deal', [
            'deal_id'     => $purchase->id,
            'entity_type' => 'AssistedPurchase',
            'entity_id'   => $purchase->id,
            'name'        => "Achat assiste #{$purchase->id}",
            'stage'       => $event->newStatus->label(),
        ]);

        // §15.2 — Devis envoyé → étape explicite Freshsales
        if ($event->newStatus === AssistedPurchaseStatus::QUOTED) {
            SyncToFreshsalesJob::dispatch('update_deal', [
                'deal_id'     => $purchase->id,
                'entity_type' => 'AssistedPurchase',
                'entity_id'   => $purchase->id,
                'name'        => "Achat assiste #{$purchase->id}",
                'stage'       => 'Devis envoyé',
                'amount'      => (float) ($purchase->total_amount ?? 0),
            ]);
        }

        // §15.2 — Paiement confirmé → étape explicite Freshsales
        if ($event->newStatus === AssistedPurchaseStatus::PAID) {
            SyncToFreshsalesJob::dispatch('update_deal', [
                'deal_id'     => $purchase->id,
                'entity_type' => 'AssistedPurchase',
                'entity_id'   => $purchase->id,
                'name'        => "Achat assiste #{$purchase->id}",
                'stage'       => 'Payé',
                'amount'      => (float) ($purchase->total_amount ?? 0),
            ]);
        }

        // §15.2 — Converti → fermer le deal comme gagné
        if ($event->newStatus === AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT) {
            SyncToFreshsalesJob::dispatch('close_deal_won', [
                'entity_type'          => 'AssistedPurchase',
                'entity_id'            => $purchase->id,
                'create_followup_task' => false,
            ]);
        }
    }

    public function handleRefundStatusChanged(RefundStatusChanged $event): void
    {
        $refund = $event->refund;

        if ($event->newStatus === \App\Enums\RefundStatus::Requested->value) {
            SyncToFreshsalesJob::dispatch('create_ticket', [
                'entity_type' => 'Refund',
                'entity_id'   => $refund->id,
                'subject'     => "Remboursement {$refund->reference_code}",
                'description' => $refund->reason,
                'contact_id'  => $refund->client_id,
                'status'      => 'open',
            ]);

            return;
        }

        if ($event->newStatus === \App\Enums\RefundStatus::Completed->value) {
            SyncToFreshsalesJob::dispatch('close_ticket', [
                'entity_type' => 'Refund',
                'entity_id'   => $refund->id,
            ]);
        }
    }

    /**
     * §15.3 — Construction des tags de segmentation du contact Freshsales.
     * Encode : unité métier, type client, zone géographique.
     */
    private function buildContactTags(mixed $user): array
    {
        $tags = ['monrespro_client'];

        if ($user->agency) {
            $tags[] = 'agence_' . \Illuminate\Support\Str::slug($user->agency->name ?? 'inconnu');
        }

        if ($user->profile?->country) {
            $countryName = $user->profile->country->name;
            if (is_array($countryName)) {
                $countryName = $countryName['fr'] ?? $countryName['en'] ?? reset($countryName);
            }
            $tags[] = 'pays_' . \Illuminate\Support\Str::slug((string) ($countryName ?? 'inconnu'));
        }

        return $tags;
    }
}

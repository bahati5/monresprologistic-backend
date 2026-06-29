<?php

namespace App\Listeners;

use App\Enums\RefundStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Events\PickupStatusChanged;
use App\Events\RefundStatusChanged;
use App\Events\ShipmentStatusChanged;
use App\Services\NotificationDispatcher;
use App\Support\ClientInAppNotificationLinks;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyClientOnStatusChange implements ShouldQueue
{
    public function handleShipmentStatusChanged(ShipmentStatusChanged $event): void
    {
        $shipment = $event->shipment;
        $user = $shipment->creator;

        if (! $user) {
            return;
        }

        NotificationDispatcher::dispatch(
            user: $user,
            eventKey: 'shipment.status_changed',
            variables: [
                'tracking' => $shipment->public_tracking ?? '',
                'status' => $event->newStatus->label(),
                'client_nom' => $user->name ?? '',
            ],
            actionUrl: ClientInAppNotificationLinks::forUser($user, "/shipments/{$shipment->id}"),
        );
    }

    public function handleAssistedPurchaseStatusChanged(AssistedPurchaseStatusChanged $event): void
    {
        $purchase = $event->purchase;
        $user = $purchase->user;

        if (! $user) {
            return;
        }

        NotificationDispatcher::dispatch(
            user: $user,
            eventKey: 'assisted_purchase.status_changed',
            variables: [
                'status' => $event->newStatus->label(),
                'client_nom' => $user->name ?? '',
            ],
            actionUrl: ClientInAppNotificationLinks::forUser($user, "/purchase-orders/{$purchase->id}"),
        );
    }

    public function handlePickupStatusChanged(PickupStatusChanged $event): void
    {
        $pickup = $event->pickup;
        $user = $pickup->user ?? null;

        if (! $user) {
            return;
        }

        NotificationDispatcher::dispatch(
            user: $user,
            eventKey: 'pickup.status_changed',
            variables: [
                'status' => $event->newStatus->label(),
                'client_nom' => $user->name ?? '',
            ],
            actionUrl: ClientInAppNotificationLinks::forUser($user, '/pickups'),
        );

        // §8.5 — Quand le chauffeur passe EN_ROUTE, envoyer un SMS au destinataire
        if ($event->newStatus === \App\Enums\PickupStatus::EnRoute) {
            $reference = $pickup->shipment?->public_tracking ?? "#{$pickup->id}";
            NotificationDispatcher::dispatch(
                user: $user,
                eventKey: 'pickup.en_route',
                variables: [
                    'reference' => $reference,
                    'client_nom' => $user->name ?? '',
                ],
                actionUrl: ClientInAppNotificationLinks::forUser($user, '/pickups'),
            );
        }
    }

    public function handleRefundStatusChanged(RefundStatusChanged $event): void
    {
        $refund = $event->refund;
        $user = $refund->client;

        if (! $user) {
            return;
        }

        $statusLabel = RefundStatus::tryFrom($event->newStatus)?->label() ?? $event->newStatus;

        NotificationDispatcher::dispatch(
            user: $user,
            eventKey: 'refund.status_changed',
            variables: [
                'status' => $statusLabel,
                'amount' => number_format((float) $refund->amount, 2),
                'client_nom' => $user->name ?? '',
            ],
            actionUrl: ClientInAppNotificationLinks::forUser($user, '/finance/refunds'),
        );
    }
}

<?php

namespace App\Enums;

/**
 * Statuts unifiés expédition / colis hub (comptoir + pré-alerte).
 */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case PendingDropOff = 'pending_drop_off';
    case ReceivedAtHub = 'received_at_hub';
    case ReadyForDispatch = 'ready_for_dispatch';
    case InTransit = 'in_transit';
    case ArrivedAtDestination = 'arrived_at_destination';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingDropOff => 'En attente de dépôt',
            self::ReceivedAtHub => 'Réceptionné au hub',
            self::ReadyForDispatch => 'Prêt à l’expédition',
            self::InTransit => 'En transit',
            self::ArrivedAtDestination => 'Arrivé à destination',
            self::Delivered => 'Livré',
            self::Cancelled => 'Annulé',
        };
    }

    /** Flux principal (hors annulation). */
    public static function orderedFlow(): array
    {
        return [
            self::Draft,
            self::PendingDropOff,
            self::ReceivedAtHub,
            self::ReadyForDispatch,
            self::InTransit,
            self::ArrivedAtDestination,
            self::Delivered,
        ];
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingDropOff, self::ReceivedAtHub, self::Cancelled],
            self::PendingDropOff => [self::ReceivedAtHub, self::Cancelled],
            self::ReceivedAtHub => [self::ReadyForDispatch, self::InTransit, self::Cancelled],
            self::ReadyForDispatch => [self::InTransit, self::Cancelled],
            self::InTransit => [self::ArrivedAtDestination, self::Cancelled],
            self::ArrivedAtDestination => [self::Delivered, self::Cancelled],
            self::Delivered => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Accepte une chaîne DB ou une instance déjà castée (ex. agrégation Eloquent sur Shipment).
     */
    public static function tryFromString(null|string|self $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}

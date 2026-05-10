<?php

namespace App\Enums;

/**
 * Statuts unifiés expédition / colis hub (comptoir + pré-alerte).
 */
enum ShipmentStatus: string
{
    /** @deprecated Les brouillons sont maintenant gérés via form_drafts. Conservé pour compatibilité historique. */
    case Draft = 'draft';
    case PendingDropOff = 'pending_drop_off';
    case ReceivedAtHub = 'received_at_hub';
    case ReadyForDispatch = 'ready_for_dispatch';
    case InTransit = 'in_transit';
    case CustomsHold = 'customs_hold';
    case ArrivedAtDestination = 'arrived_at_destination';
    case DeliveryFailed = 'delivery_failed';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    /** Pré-alerte non déposée au-delà du délai (§7.2 PRD). */
    case Expired = 'expired';
    /** Problème signalé par le hub (file SAV / traitement). */
    case IssueReported = 'issue_reported';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingDropOff => 'En attente de dépôt',
            self::ReceivedAtHub => 'Réceptionné au hub',
            self::ReadyForDispatch => 'Prêt à l\'expédition',
            self::InTransit => 'En transit',
            self::CustomsHold => 'Blocage douane',
            self::ArrivedAtDestination => 'Arrivé à destination',
            self::DeliveryFailed => 'Échec de livraison',
            self::Delivered => 'Livré',
            self::Cancelled => 'Annulé',
            self::Expired => 'Expiré',
            self::IssueReported => 'Problème signalé',
        };
    }

    /** Flux principal (hors annulation et blocage douane). */
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
            self::Draft => [self::PendingDropOff, self::ReceivedAtHub, self::IssueReported, self::Cancelled],
            self::PendingDropOff => [self::ReceivedAtHub, self::IssueReported, self::Cancelled, self::Expired],
            self::ReceivedAtHub => [self::ReadyForDispatch, self::InTransit, self::Cancelled],
            self::ReadyForDispatch => [self::InTransit, self::Cancelled],
            self::InTransit => [self::ArrivedAtDestination, self::CustomsHold, self::Cancelled],
            self::CustomsHold => [self::InTransit, self::ArrivedAtDestination, self::Cancelled],
            self::ArrivedAtDestination => [self::Delivered, self::DeliveryFailed, self::Cancelled],
            self::DeliveryFailed => [self::ArrivedAtDestination, self::Cancelled],
            self::Delivered => [],
            self::Cancelled => [],
            self::Expired => [],
            self::IssueReported => [self::ReceivedAtHub, self::Cancelled],
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

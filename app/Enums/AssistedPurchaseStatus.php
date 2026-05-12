<?php

namespace App\Enums;

enum AssistedPurchaseStatus: string
{
    case PENDING_QUOTE = 'pending_quote';
    case AWAITING_CLIENT_INFO = 'awaiting_client_info';
    case QUOTED = 'quoted';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAID = 'paid';
    case ORDERED = 'ordered';
    case ARRIVED_AT_HUB = 'arrived_at_hub';
    case CONVERTED_TO_SHIPMENT = 'converted_to_shipment';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_QUOTE => 'En cours de chiffrage',
            self::AWAITING_CLIENT_INFO => 'En attente d\'informations client',
            self::QUOTED => 'Devis envoyé',
            self::AWAITING_PAYMENT => 'En attente de paiement',
            self::PAID => 'Paiement validé',
            self::ORDERED => 'Acheté chez le fournisseur',
            self::ARRIVED_AT_HUB => 'Colis reçu à l\'entrepôt',
            self::CONVERTED_TO_SHIPMENT => 'Converti en expédition',
            self::EXPIRED => 'Devis expiré',
            self::FAILED => 'Échec — produit indisponible',
            self::CANCELLED => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING_QUOTE => 'bg-amber-100 text-amber-800',
            self::AWAITING_CLIENT_INFO => 'bg-yellow-100 text-yellow-800',
            self::QUOTED => 'bg-sky-100 text-sky-800',
            self::AWAITING_PAYMENT => 'bg-blue-100 text-blue-800',
            self::PAID => 'bg-emerald-100 text-emerald-800',
            self::ORDERED => 'bg-purple-100 text-purple-800',
            self::ARRIVED_AT_HUB => 'bg-green-100 text-green-800',
            self::CONVERTED_TO_SHIPMENT => 'bg-indigo-100 text-indigo-800',
            self::EXPIRED => 'bg-orange-100 text-orange-800',
            self::FAILED => 'bg-red-100 text-red-800',
            self::CANCELLED => 'bg-gray-100 text-gray-800',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PENDING_QUOTE => [self::QUOTED, self::AWAITING_CLIENT_INFO, self::AWAITING_PAYMENT, self::FAILED, self::CANCELLED],
            self::AWAITING_CLIENT_INFO => [self::PENDING_QUOTE, self::CANCELLED],
            self::QUOTED => [self::AWAITING_PAYMENT, self::EXPIRED, self::CANCELLED],
            self::AWAITING_PAYMENT => [self::PAID, self::QUOTED, self::EXPIRED, self::CANCELLED],
            self::PAID => [self::ORDERED, self::CANCELLED],
            self::ORDERED => [self::ARRIVED_AT_HUB, self::FAILED, self::CANCELLED],
            self::ARRIVED_AT_HUB => [self::CONVERTED_TO_SHIPMENT],
            self::CONVERTED_TO_SHIPMENT => [],
            self::EXPIRED => [self::QUOTED],
            self::FAILED => [],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

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

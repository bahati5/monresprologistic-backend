<?php

namespace App\Enums;

enum AssistedPurchaseStatus: string
{
    case PENDING_QUOTE = 'pending_quote';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAID = 'paid';
    case ORDERED = 'ordered';
    case ARRIVED_AT_HUB = 'arrived_at_hub';
    case CONVERTED_TO_SHIPMENT = 'converted_to_shipment';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_QUOTE => 'En cours de chiffrage',
            self::AWAITING_PAYMENT => 'Devis disponible',
            self::PAID => 'Paiement validé',
            self::ORDERED => 'Acheté chez le fournisseur',
            self::ARRIVED_AT_HUB => 'Colis reçu à l\'entrepôt',
            self::CONVERTED_TO_SHIPMENT => 'Converti en expédition',
            self::CANCELLED => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING_QUOTE => 'bg-amber-100 text-amber-800',
            self::AWAITING_PAYMENT => 'bg-blue-100 text-blue-800',
            self::PAID => 'bg-emerald-100 text-emerald-800',
            self::ORDERED => 'bg-purple-100 text-purple-800',
            self::ARRIVED_AT_HUB => 'bg-green-100 text-green-800',
            self::CONVERTED_TO_SHIPMENT => 'bg-indigo-100 text-indigo-800',
            self::CANCELLED => 'bg-red-100 text-red-800',
        };
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

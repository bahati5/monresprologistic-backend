<?php

namespace App\Enums;

enum FormDraftType: string
{
    case Shipment = 'shipment';
    case PreAlert = 'pre_alert';
    case AssistedPurchase = 'assisted_purchase';
    case Quote = 'quote';
    case RefundRequest = 'refund_request';
    case Pickup = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::Shipment => 'Expédition',
            self::PreAlert => 'Pré-alerte',
            self::AssistedPurchase => 'Achat assisté',
            self::Quote => 'Devis',
            self::RefundRequest => 'Remboursement',
            self::Pickup => 'Ramassage',
        };
    }

    public function defaultRoute(): string
    {
        return match ($this) {
            self::Shipment => '/shipments/create',
            self::PreAlert => '/shipment-notices',
            self::AssistedPurchase => '/shopping-assiste/nouveau',
            self::Quote => '/purchase-orders',
            self::RefundRequest => '/finance/refunds',
            self::Pickup => '/pickups',
        };
    }
}

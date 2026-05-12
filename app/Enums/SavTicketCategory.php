<?php

namespace App\Enums;

enum SavTicketCategory: string
{
    case LostDamaged = 'LOST_DAMAGED';
    case DeliveryDelay = 'DELIVERY_DELAY';
    case NonConforming = 'NON_CONFORMING';
    case RefundIssue = 'REFUND_ISSUE';
    case PaymentIssue = 'PAYMENT_ISSUE';
    case CustomsIssue = 'CUSTOMS_ISSUE';
    case ClientUnreachable = 'CLIENT_UNREACHABLE';
    case GeneralQuestion = 'GENERAL_QUESTION';
    case AccountIssue = 'ACCOUNT_ISSUE';
    case QuoteRequest = 'QUOTE_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::LostDamaged => 'Colis perdu ou endommagé',
            self::DeliveryDelay => 'Retard de livraison',
            self::NonConforming => 'Article non conforme',
            self::RefundIssue => 'Remboursement en attente',
            self::PaymentIssue => 'Problème de paiement',
            self::CustomsIssue => 'Réclamation douane',
            self::ClientUnreachable => 'Client injoignable',
            self::GeneralQuestion => 'Question générale',
            self::AccountIssue => 'Problème de compte',
            self::QuoteRequest => 'Demande de devis/info',
            self::Other => 'Autre',
        };
    }

    public function family(): string
    {
        return match ($this) {
            self::LostDamaged, self::DeliveryDelay, self::NonConforming,
            self::RefundIssue, self::PaymentIssue, self::CustomsIssue,
            self::ClientUnreachable => 'A',
            default => 'B',
        };
    }

    public function defaultPriority(): string
    {
        return match ($this) {
            self::LostDamaged, self::RefundIssue, self::PaymentIssue => 'urgent',
            self::ClientUnreachable => 'low',
            default => 'normal',
        };
    }
}

<?php

namespace App\Enums;

enum PickupStatus: string
{
    case Draft = 'draft';
    case DriverAssigned = 'driver_assigned';
    case Accepted = 'accepted';
    case EnRoute = 'en_route';
    case Collected = 'collected';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Demandé',
            self::DriverAssigned => 'Chauffeur assigné',
            self::Accepted => 'Accepté',
            self::EnRoute => 'En route',
            self::Collected => 'Collecté',
            self::Delivered => 'Livré',
            self::Completed => 'Terminé',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulé',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::DriverAssigned, self::Cancelled],
            self::DriverAssigned => [self::Accepted, self::Cancelled],
            self::Accepted => [self::EnRoute, self::Cancelled],
            self::EnRoute => [self::Collected, self::Delivered, self::Failed],
            self::Collected => [self::Completed, self::Cancelled],
            self::Delivered => [self::Completed],
            self::Completed => [],
            self::Failed => [self::Draft],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

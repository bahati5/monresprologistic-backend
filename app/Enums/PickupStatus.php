<?php

namespace App\Enums;

enum PickupStatus: string
{
    case Draft = 'draft';
    case DriverAssigned = 'driver_assigned';
    case Accepted = 'accepted';
    case Collected = 'collected';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Demandé',
            self::DriverAssigned => 'Chauffeur assigné',
            self::Accepted => 'Accepté',
            self::Collected => 'Collecté',
            self::Completed => 'Terminé',
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
            self::Accepted => [self::Collected, self::Cancelled],
            self::Collected => [self::Completed, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

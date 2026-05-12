<?php

namespace App\Enums;

enum SavTicketPriority: string
{
    case Urgent = 'urgent';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgent',
            self::Normal => 'Normal',
            self::Low => 'Faible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'bg-red-100 text-red-800',
            self::Normal => 'bg-orange-100 text-orange-800',
            self::Low => 'bg-yellow-100 text-yellow-800',
        };
    }

    /** First response SLA in minutes (business hours). */
    public function firstResponseMinutes(): int
    {
        return match ($this) {
            self::Urgent => 120,
            self::Normal => 240,
            self::Low => 1440,
        };
    }

    /** Resolution target in hours. */
    public function resolutionTargetHours(): int
    {
        return match ($this) {
            self::Urgent => 24,
            self::Normal => 72,
            self::Low => 168,
        };
    }
}

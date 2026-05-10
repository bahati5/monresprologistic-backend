<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processed = 'processed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Demandé',
            self::UnderReview => 'En cours d\'examen',
            self::Approved => 'Approuvé',
            self::Rejected => 'Rejeté',
            self::Processed => 'Traité',
            self::Completed => 'Terminé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'bg-amber-100 text-amber-800',
            self::UnderReview => 'bg-blue-100 text-blue-800',
            self::Approved => 'bg-emerald-100 text-emerald-800',
            self::Rejected => 'bg-red-100 text-red-800',
            self::Processed => 'bg-purple-100 text-purple-800',
            self::Completed => 'bg-green-100 text-green-800',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::Processed],
            self::Rejected => [],
            self::Processed => [self::Completed],
            self::Completed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

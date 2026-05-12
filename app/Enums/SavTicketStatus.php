<?php

namespace App\Enums;

enum SavTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingClient = 'waiting_client';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouvert',
            self::InProgress => 'En cours',
            self::WaitingClient => 'En attente client',
            self::Escalated => 'Escaladé',
            self::Resolved => 'Résolu',
            self::Closed => 'Fermé',
            self::Cancelled => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'bg-blue-100 text-blue-800',
            self::InProgress => 'bg-amber-100 text-amber-800',
            self::WaitingClient => 'bg-purple-100 text-purple-800',
            self::Escalated => 'bg-red-100 text-red-800',
            self::Resolved => 'bg-emerald-100 text-emerald-800',
            self::Closed => 'bg-gray-100 text-gray-800',
            self::Cancelled => 'bg-gray-100 text-gray-500',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Escalated, self::Cancelled],
            self::InProgress => [self::WaitingClient, self::Escalated, self::Resolved, self::Cancelled],
            self::WaitingClient => [self::InProgress, self::Escalated],
            self::Escalated => [self::InProgress, self::Resolved],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function slaSuspended(): bool
    {
        return in_array($this, [self::WaitingClient, self::Resolved, self::Closed, self::Cancelled]);
    }
}

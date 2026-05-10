<?php

namespace App\Listeners;

use App\Enums\RefundStatus;
use App\Events\RefundStatusChanged;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStaffOnClientRefundRequest implements ShouldQueue
{
    public function handle(RefundStatusChanged $event): void
    {
        $refund = $event->refund;
        $actor = $event->changedBy;

        if (! $actor?->hasRole('client')) {
            return;
        }

        if ($event->newStatus !== RefundStatus::Requested->value) {
            return;
        }

        $recipients = $this->resolveRecipients($refund->agency_id);

        foreach ($recipients as $user) {
            if ((int) $user->id === (int) $actor->id) {
                continue;
            }

            NotificationDispatcher::dispatch(
                user: $user,
                eventKey: 'refund.client_requested',
                variables: [
                    'reference_code' => $refund->reference_code,
                    'amount' => number_format((float) $refund->amount, 2),
                    'currency' => $refund->currency ?? 'USD',
                    'client_nom' => $refund->client?->name ?? $actor->name ?? '',
                ],
                actionUrl: '/finance/refunds',
            );
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function resolveRecipients(?int $agencyId)
    {
        if ($agencyId) {
            return User::query()
                ->where('agency_id', $agencyId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['agency_admin', 'super_admin', 'operator']))
                ->get();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();
    }
}

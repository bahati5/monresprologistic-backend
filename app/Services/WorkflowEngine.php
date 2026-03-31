<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\Status;
use App\Models\StatusTransition;
use Illuminate\Validation\ValidationException;

class WorkflowEngine
{
    public function assertTransitionAllowed(Status $from, Status $to): void
    {
        $allowed = StatusTransition::query()
            ->where('from_status_id', $from->id)
            ->where('to_status_id', $to->id)
            ->exists();

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => ['Transition de statut non autorisée.'],
            ]);
        }
    }

    public function transition(Shipment $shipment, Status $to, ?int $userId = null, ?string $message = null): void
    {
        $from = $shipment->status;

        if ($from) {
            $this->assertTransitionAllowed($from, $to);
        }

        $shipment->status()->associate($to);
        $shipment->save();

        $shipment->logs()->create([
            'user_id' => $userId,
            'status_id' => $to->id,
            'title' => $message ?? $to->getTranslation('name', app()->getLocale()),
            'ip_address' => request()?->ip(),
        ]);
    }
}

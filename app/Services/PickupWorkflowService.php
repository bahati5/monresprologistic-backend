<?php

namespace App\Services;

use App\Enums\PickupStatus;
use App\Models\Pickup;
use Illuminate\Support\Collection;

class PickupWorkflowService
{
    /**
     * @return Collection<int, array{code: string, label: string}>
     */
    public function getAvailableTransitions(Pickup $pickup): Collection
    {
        $current = $pickup->status ?? PickupStatus::Draft;

        return collect($current->allowedNext())->map(fn (PickupStatus $s) => [
            'code' => $s->value,
            'label' => $s->label(),
        ]);
    }
}

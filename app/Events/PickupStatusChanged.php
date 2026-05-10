<?php

namespace App\Events;

use App\Enums\PickupStatus;
use App\Models\Pickup;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PickupStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Pickup $pickup,
        public readonly PickupStatus $oldStatus,
        public readonly PickupStatus $newStatus,
        public readonly ?User $changedBy = null,
    ) {}
}

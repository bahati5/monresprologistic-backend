<?php

namespace App\Events;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistedPurchaseStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AssistedPurchase $purchase,
        public readonly AssistedPurchaseStatus $oldStatus,
        public readonly AssistedPurchaseStatus $newStatus,
        public readonly ?User $changedBy = null,
    ) {}
}

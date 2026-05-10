<?php

namespace App\Events;

use App\Models\Refund;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Refund $refund,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?User $changedBy = null,
    ) {}
}

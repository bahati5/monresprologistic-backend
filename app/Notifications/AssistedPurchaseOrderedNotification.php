<?php

namespace App\Notifications;

use App\Mail\AssistedPurchaseOrderedMail;
use App\Models\AssistedPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssistedPurchaseOrderedNotification extends Notification
{
    use Queueable;

    public function __construct(public AssistedPurchase $purchase)
    {
        $this->purchase->loadMissing('user');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): AssistedPurchaseOrderedMail
    {
        return new AssistedPurchaseOrderedMail($this->purchase);
    }
}

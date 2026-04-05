<?php

namespace App\Notifications;

use App\Mail\AssistedPurchaseQuoteMail;
use App\Models\AssistedPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QuoteReadyNotification extends Notification
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

    public function toMail(object $notifiable): AssistedPurchaseQuoteMail
    {
        return new AssistedPurchaseQuoteMail($this->purchase);
    }
}

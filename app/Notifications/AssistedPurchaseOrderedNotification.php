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
        $mail = new AssistedPurchaseOrderedMail($this->purchase);

        $recipients = $notifiable->routeNotificationFor('mail', $this);
        if ($recipients === null || $recipients === '') {
            $recipients = property_exists($notifiable, 'email') ? $notifiable->email : null;
        }

        if (is_array($recipients)) {
            return $mail->to($recipients);
        }

        $email = is_string($recipients) ? trim($recipients) : '';
        if ($email === '') {
            return $mail;
        }

        $name = property_exists($notifiable, 'name') ? trim((string) $notifiable->name) : '';

        return $name !== '' ? $mail->to($email, $name) : $mail->to($email);
    }
}

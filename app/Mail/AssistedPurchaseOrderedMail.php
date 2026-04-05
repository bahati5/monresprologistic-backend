<?php

namespace App\Mail;

use App\Models\AssistedPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssistedPurchaseOrderedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AssistedPurchase $purchase)
    {
        $this->purchase->loadMissing('user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos articles ont été commandés — Monrespro',
        );
    }

    public function content(): Content
    {
        $tracking = $this->purchase->supplier_tracking_number;
        $tracking = is_string($tracking) && trim($tracking) !== '' ? trim($tracking) : null;

        return new Content(
            view: 'mail.assisted-purchase-ordered',
            with: [
                'reference' => (string) $this->purchase->id,
                'tracking' => $tracking,
            ],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\AssistedPurchase;
use App\Support\AssistedPurchaseQuotePresentation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssistedPurchaseQuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var list<array{name: string, quantity: int, unit_formatted: string, line_formatted: string}> */
    public array $quoteRows;

    public string $clientFirstName;

    public string $linesSubtotalFormatted;

    public string $serviceFeeFormatted;

    public string $bankFeeFormatted;

    public string $bankFeePercentageLabel;

    public string $totalFormatted;

    public string $currency;

    public string $paymentUrl;

    public ?string $paymentMethodsNote;

    public function __construct(public AssistedPurchase $purchase)
    {
        $this->purchase->loadMissing([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'items',
        ]);

        $p = AssistedPurchaseQuotePresentation::forPurchase($this->purchase);
        $this->quoteRows = $p['quoteRows'];
        $this->clientFirstName = $p['clientFirstName'];
        $this->linesSubtotalFormatted = $p['linesSubtotalFormatted'];
        $this->serviceFeeFormatted = $p['serviceFeeFormatted'];
        $this->bankFeeFormatted = $p['bankFeeFormatted'];
        $this->bankFeePercentageLabel = $p['bankFeePercentageLabel'];
        $this->totalFormatted = $p['totalFormatted'];
        $this->currency = $p['currency'];
        $this->paymentUrl = $p['paymentUrl'];
        $this->paymentMethodsNote = $p['paymentMethodsNote'];
    }

    public function envelope(): Envelope
    {
        $app = config('app.name', 'Monrespro');

        return new Envelope(
            subject: $app.' — Votre devis achat assisté #'.$this->purchase->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.assisted-purchase-quote',
            text: 'mail.assisted-purchase-quote-text',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->purchase->loadMissing([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'items',
        ]);

        try {
            $present = AssistedPurchaseQuotePresentation::forPurchase($this->purchase);
            $clientRows = AssistedPurchaseQuotePresentation::clientDetailRows($this->purchase);
            $quotedAt = $this->purchase->quoted_at ?? now();

            $html = view('pdf.assisted-purchase-quote', [
                'purchase' => $this->purchase,
                'present' => $present,
                'clientRows' => $clientRows,
                'quotedAtFormatted' => $quotedAt->timezone(config('app.timezone'))->translatedFormat('d F Y'),
                'accent' => '#3d3d69',
            ])->render();

            $binary = Pdf::loadHTML($html)
                ->setPaper('a4')
                ->setOption('defaultFont', 'DejaVu Sans')
                ->setOption('isRemoteEnabled', true)
                ->output();

            return [
                Attachment::fromData(fn () => $binary, 'devis-achat-assiste-'.$this->purchase->id.'.pdf')
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}

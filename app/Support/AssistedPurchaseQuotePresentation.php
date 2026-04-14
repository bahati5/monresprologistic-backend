<?php

namespace App\Support;

use App\Models\AssistedPurchase;
use App\Models\Setting;
use App\Models\User;

/**
 * Données communes pour l’e-mail Markdown et le PDF de devis achat assisté.
 */
final class AssistedPurchaseQuotePresentation
{
    /**
     * @return array{
     *   quoteRows: list<array{name: string, quantity: int, unit_formatted: string, line_formatted: string}>,
     *   clientFirstName: string,
     *   linesSubtotalFormatted: string,
     *   serviceFeeFormatted: string,
     *   bankFeeFormatted: string,
     *   bankFeePercentageLabel: string,
     *   totalFormatted: string,
     *   currency: string,
     *   paymentUrl: string,
     *   paymentMethodsNote: ?string,
     *   doc: array<string, mixed>
     * }
     */
    public static function forPurchase(AssistedPurchase $purchase): array
    {
        $purchase->loadMissing(['user', 'items']);

        $doc = ShipmentDocumentSettings::merged();
        $currency = (string) ($purchase->quote_currency ?? $doc['currency'] ?? 'EUR');

        $symbol = trim((string) ($doc['currency_symbol'] ?? ''));
        if ($symbol === '') {
            $symbol = match (strtoupper($currency)) {
                'EUR' => '€',
                'USD' => '$',
                'GBP' => '£',
                default => $currency,
            };
        }

        $suffix = ((string) (Setting::getValue('symbol_position', 'prefix') ?: 'prefix')) === 'suffix';
        $decimals = max(0, min(6, (int) ($doc['decimals'] ?? 2)));

        $fmt = static function (float $n) use ($symbol, $suffix, $decimals): string {
            $num = number_format($n, $decimals, ',', ' ');

            return $suffix ? $num."\u{a0}".$symbol : $symbol.$num;
        };

        $linesSubtotal = 0.0;
        $rows = [];
        foreach ($purchase->items as $item) {
            $qty = (int) $item->quantity;
            $unit = (float) $item->unit_price;
            $line = $unit * $qty;
            $linesSubtotal += $line;
            $rows[] = [
                'name' => (string) $item->display_label,
                'quantity' => $qty,
                'unit_formatted' => $fmt($unit),
                'line_formatted' => $fmt($line),
            ];
        }

        $serviceFee = (float) ($purchase->service_fee ?? 0);
        $bankPct = (float) ($purchase->bank_fee_percentage ?? 3);
        $bankBase = $linesSubtotal + $serviceFee;
        $bankFee = $bankBase * ($bankPct / 100.0);
        $total = (float) ($purchase->total_amount ?? $purchase->quote_amount ?? ($linesSubtotal + $serviceFee + $bankFee));

        $user = $purchase->user;
        $rawName = $user ? (is_string($user->name) ? $user->name : (string) $user->name) : '';
        $clientFirstName = trim(explode(' ', $rawName)[0] ?? '') ?: 'cher client';

        $base = FrontendPortalUrl::base();
        $paymentUrl = $base.'/purchase-orders/'.$purchase->id;

        $note = $purchase->payment_methods_note;
        $paymentMethodsNote = $note !== null && trim($note) !== '' ? trim($note) : null;

        return [
            'quoteRows' => $rows,
            'clientFirstName' => $clientFirstName,
            'linesSubtotalFormatted' => $fmt($linesSubtotal),
            'serviceFeeFormatted' => $fmt($serviceFee),
            'bankFeeFormatted' => $fmt($bankFee),
            'bankFeePercentageLabel' => number_format($bankPct, 2, ',', ' ').' %',
            'totalFormatted' => $fmt($total),
            'currency' => $currency,
            'paymentUrl' => $paymentUrl,
            'paymentMethodsNote' => $paymentMethodsNote,
            'doc' => $doc,
        ];
    }

    /**
     * Lignes client pour PDF / UI (libellé + valeur).
     *
     * @return list<array{label: string, value: string}>
     */
    public static function clientDetailRows(AssistedPurchase $purchase): array
    {
        $purchase->loadMissing([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
        ]);

        $u = $purchase->user;
        if (! $u instanceof User) {
            return [];
        }

        $prof = $u->profile;
        $rows = [];

        $name = trim((string) ($u->name ?? ''));
        if ($name !== '') {
            $rows[] = ['label' => 'Nom', 'value' => $name];
        }

        $email = trim((string) ($u->email ?? ''));
        if ($email !== '') {
            $rows[] = ['label' => 'E-mail', 'value' => $email];
        }

        $phoneUser = trim((string) ($u->phone ?? ''));
        $phoneProf = $prof ? trim((string) ($prof->phone ?? '')) : '';
        if ($phoneUser !== '') {
            $rows[] = ['label' => 'Téléphone', 'value' => $phoneUser];
        } elseif ($phoneProf !== '') {
            $rows[] = ['label' => 'Téléphone', 'value' => $phoneProf];
        }

        $phone2 = $prof ? trim((string) ($prof->phone_secondary ?? '')) : '';
        if ($phone2 !== '') {
            $rows[] = ['label' => 'Téléphone secondaire', 'value' => $phone2];
        }

        $locker = trim((string) ($u->locker_number ?? ''));
        if ($locker !== '') {
            $rows[] = ['label' => 'Casier', 'value' => $locker];
        }

        if ($prof) {
            $landmark = trim((string) ($prof->landmark ?? ''));
            if ($landmark !== '') {
                $rows[] = ['label' => 'Repère / point de repère', 'value' => $landmark];
            }

            $addrLines = [];
            $street = trim((string) ($prof->address ?? ''));
            if ($street !== '') {
                $addrLines[] = $street;
            }
            $zc = trim((string) ($prof->zip_code ?? ''));
            $cityName = $prof->relationLoaded('city') && $prof->city
                ? trim((string) $prof->city->name)
                : '';
            $cityLine = trim($zc.' '.$cityName);
            if ($cityLine !== '') {
                $addrLines[] = $cityLine;
            }
            $stateName = $prof->relationLoaded('state') && $prof->state
                ? trim((string) $prof->state->name)
                : '';
            if ($stateName !== '') {
                $addrLines[] = $stateName;
            }
            $countryName = $prof->relationLoaded('country') && $prof->country
                ? trim((string) $prof->country->name)
                : '';
            if ($countryName !== '') {
                $addrLines[] = $countryName;
            }
            if ($addrLines !== []) {
                $rows[] = ['label' => 'Adresse', 'value' => implode("\n", $addrLines)];
            }
        }

        return $rows;
    }
}

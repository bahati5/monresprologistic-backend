<?php

namespace App\Support;

use App\Models\ExchangeRate;
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
     *   sourcePriceFormatted: ?string,
     *   sourceCurrency: ?string,
     *   appliedRateLabel: string,
     *   appliedRateVerbose: string,
     *   appliedRateSourceNote: string,
     *   appliedRateAsOf: ?string,
     *   totalCdfFormatted: ?string,
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
        $fmtWithCurrency = static function (float $n, string $cur) use ($decimals): string {
            $num = number_format($n, $decimals, ',', ' ');
            $sym = match (strtoupper($cur)) {
                'EUR' => '€',
                'USD' => '$',
                'GBP' => '£',
                'CDF' => 'CDF',
                default => strtoupper($cur),
            };

            return $sym === 'CDF' ? $num."\u{a0}CDF" : $sym.$num;
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
        $quoteAmount = (float) ($purchase->quote_amount ?? $total);

        $sourceCurrency = $purchase->price_currency ? strtoupper((string) $purchase->price_currency) : null;
        $sourcePrice = $purchase->price_displayed !== null ? (float) $purchase->price_displayed : null;

        $effectiveRate = 1.0;
        $tableRateRecord = null;
        $appliedRateSourceNote = 'Même devise de devis : aucune conversion appliquée.';
        $appliedRateAsOf = null;

        if ($sourcePrice !== null && $sourcePrice > 0 && $quoteAmount > 0 && $sourceCurrency && strtoupper($currency) !== $sourceCurrency) {
            $effectiveRate = $quoteAmount / $sourcePrice;
            $appliedRateSourceNote = 'Taux dérivé du rapport entre le montant TTC du devis et le prix source (fiche produit / extraction), distinct du tableau de change.';
        } else {
            $pairFrom = $sourceCurrency ?: strtoupper($currency);
            $pairTo = strtoupper($currency);
            $tableRateRecord = ExchangeRate::currentRecord($pairFrom, $pairTo);
            $stored = $tableRateRecord?->rate !== null ? (float) $tableRateRecord->rate : null;
            if ($stored !== null && $stored > 0) {
                $effectiveRate = $stored;
                $appliedRateSourceNote = 'Taux publié dans le tableau de change de l’application (traçabilité : enregistrement horodaté).';
                if ($tableRateRecord->valid_from) {
                    $appliedRateAsOf = $tableRateRecord->valid_from
                        ->timezone(config('app.timezone'))
                        ->format('d/m/Y H:i');
                }
            } elseif (strtoupper($currency) === strtoupper((string) ($sourceCurrency ?? $currency))) {
                $appliedRateSourceNote = 'Même devise : aucune conversion.';
            } else {
                $appliedRateSourceNote = 'Aucun taux enregistré pour cette paire : le taux affiché est indicatif (1,000000 par défaut).';
            }
        }

        $rateLabel = number_format($effectiveRate, 6, ',', ' ');
        $rateVerbose = ($sourceCurrency && strtoupper($sourceCurrency) !== strtoupper($currency))
            ? sprintf('1 %s = %s %s', strtoupper($sourceCurrency), $rateLabel, strtoupper($currency))
            : sprintf('1 %s = 1,000000 %s', strtoupper($currency), strtoupper($currency));

        $cdfRate = ExchangeRate::currentRate(strtoupper($currency), 'CDF');
        $totalCdf = ($cdfRate !== null && $cdfRate > 0) ? $total * $cdfRate : null;

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
            'sourcePriceFormatted' => ($sourcePrice !== null && $sourceCurrency) ? $fmtWithCurrency($sourcePrice, $sourceCurrency) : null,
            'sourceCurrency' => $sourceCurrency,
            'appliedRateLabel' => $rateLabel,
            'appliedRateVerbose' => $rateVerbose,
            'appliedRateSourceNote' => $appliedRateSourceNote,
            'appliedRateAsOf' => $appliedRateAsOf,
            'totalCdfFormatted' => $totalCdf !== null ? number_format($totalCdf, 2, ',', ' ')."\u{a0}CDF" : null,
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

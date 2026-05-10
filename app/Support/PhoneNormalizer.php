<?php

namespace App\Support;

/**
 * Normalise un numéro saisi au guichet : ajout de l’indicatif pays si absent (PRD — prévention erreurs SMS).
 */
final class PhoneNormalizer
{
    public static function normalize(string $raw, ?string $countryPhoneCode): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return trim($raw);
        }

        $code = $countryPhoneCode !== null ? preg_replace('/\D+/', '', $countryPhoneCode) ?? '' : '';
        if ($code === '') {
            return trim($raw);
        }

        if (str_starts_with(trim($raw), '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, $code)) {
            return '+'.$digits;
        }

        return '+'.$code.$digits;
    }
}

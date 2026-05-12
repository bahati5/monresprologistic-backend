<?php

namespace App\Support;

/**
 * Normalise snapshot_data (json, double-encodage, stdClass) vers un tableau pour les vues / PDF / e-mails.
 */
final class QuoteSnapshotDataNormalizer
{
    /**
     * @return array<string, mixed>|null
     */
    public static function toArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \stdClass) {
            $decoded = json_decode(json_encode($value), true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (is_string($decoded) && $decoded !== '') {
                $again = json_decode($decoded, true);

                return is_array($again) ? $again : null;
            }
        }

        return null;
    }
}

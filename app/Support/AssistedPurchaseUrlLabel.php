<?php

namespace App\Support;

final class AssistedPurchaseUrlLabel
{
    /**
     * Libellé lisible quand le client n’a pas saisi de nom d’article.
     */
    public static function fromUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return 'Article';
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? preg_replace('/^www\./i', '', $host) : '';
        if ($host === '') {
            return 'Article (lien)';
        }

        if (preg_match('/amzn\.|^amazon\./i', $host)) {
            return 'Produit ('.$host.')';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';
        if ($path !== '') {
            $seg = basename($path);
            if ($seg !== '' && strlen($seg) < 120) {
                $decoded = rawurldecode($seg);
                if (preg_match('/\p{L}/u', $decoded)) {
                    return mb_strlen($decoded) > 72 ? mb_substr($decoded, 0, 69).'…' : $decoded;
                }
            }
        }

        return 'Produit ('.$host.')';
    }
}

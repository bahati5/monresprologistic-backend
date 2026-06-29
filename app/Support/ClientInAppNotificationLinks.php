<?php

namespace App\Support;

use App\Models\User;

/**
 * Chemins SPA pour les notifications in-app des comptes « client » (portail /portal/...).
 */
final class ClientInAppNotificationLinks
{
    public static function forUser(?User $user, ?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        $path = trim($path);
        if ($user === null || ! $user->hasRole('client')) {
            return $path;
        }

        return self::clientSpaFromInternalPath($path);
    }

    public static function clientSpaFromInternalPath(string $path): string
    {
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (preg_match('#^/purchase-orders/(\d+)(?:/.*)?$#', $path, $m)) {
            return '/portal/achats/'.$m[1];
        }
        if ($path === '/purchase-orders') {
            return '/portal/achats';
        }

        if (preg_match('#^/shipments/(\d+)#', $path, $m)) {
            return '/portal/expeditions/'.$m[1];
        }
        if ($path === '/shipments' || str_starts_with($path, '/shipments?')) {
            return '/portal/expeditions';
        }

        if (str_starts_with($path, '/finance/refunds')) {
            return '/portal/factures';
        }

        if ($path === '/pickups' || str_starts_with($path, '/pickups?') || preg_match('#^/pickups/#', $path)) {
            return '/portal/expeditions';
        }

        if (str_starts_with($path, '/client/locker')) {
            return '/portal/casier';
        }

        return $path;
    }
}

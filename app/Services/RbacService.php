<?php

namespace App\Services;

use App\Models\User;

class RbacService
{
    public static function userHasPermission(?User $user, string $permCode): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($permCode, $user->getAllEffectivePermissionCodes(), true);
    }

    public static function userHasAnyPermission(?User $user, array $permCodes): bool
    {
        if (! $user) {
            return false;
        }

        $effective = $user->getAllEffectivePermissionCodes();

        foreach ($permCodes as $code) {
            if (in_array($code, $effective, true)) {
                return true;
            }
        }

        return false;
    }

    public static function userHasAllPermissions(?User $user, array $permCodes): bool
    {
        if (! $user) {
            return false;
        }

        $effective = $user->getAllEffectivePermissionCodes();

        foreach ($permCodes as $code) {
            if (! in_array($code, $effective, true)) {
                return false;
            }
        }

        return true;
    }
}

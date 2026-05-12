<?php

namespace App\Http\Middleware;

use App\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasEffectivePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Non authentifié.');
        }

        foreach ($permissions as $permission) {
            if (! RbacService::userHasPermission($user, $permission)) {
                abort(403, "Permission requise : {$permission}");
            }
        }

        return $next($request);
    }
}

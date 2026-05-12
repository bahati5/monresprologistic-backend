<?php

namespace App\Http\Middleware;

use App\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasAnyEffectivePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Non authentifié.');
        }

        if (! RbacService::userHasAnyPermission($user, $permissions)) {
            abort(403, 'Aucune des permissions requises n\'est accordée.');
        }

        return $next($request);
    }
}

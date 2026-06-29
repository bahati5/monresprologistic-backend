<?php

namespace App\Http\Controllers;

use App\Services\CurrencyConverter;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Conversion indicative à partir du tableau de change (audit via `exchange_rates`).
     */
    public function convert(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasRole('client'), 403);
        abort_unless(
            $user->can('manage_settings')
                || RbacService::userHasPermission($user, 'assisted_purchase.manage')
                || $user->can('manage_finances'),
            403
        );

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'from' => ['required', 'string', 'max:8'],
            'to' => ['required', 'string', 'max:8'],
        ]);

        $from = strtoupper(trim($data['from']));
        $to = strtoupper(trim($data['to']));
        $amount = (float) $data['amount'];

        $result = CurrencyConverter::convert($amount, $from, $to);
        if ($result === null) {
            return response()->json([
                'message' => 'Aucun taux enregistré pour cette paire de devises. Ajoutez un taux dans Paramètres → Devises.',
            ], 422);
        }

        return response()->json($result);
    }
}

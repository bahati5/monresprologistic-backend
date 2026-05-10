<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\PreAlert;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * §17 — Endpoints publics pour les formulaires WordPress (sans authentification).
 * CORS est géré par le middleware 'cors' ou les headers HTTP publics.
 */
class WordPressFormController extends Controller
{
    /**
     * §17.1 — Création d'une demande d'achat assisté depuis WordPress.
     * Le client peut être identifié par son email ou son numéro de locker.
     */
    public function createAssistedPurchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'           => ['required', 'email', 'max:255'],
            'locker_number'   => ['nullable', 'string', 'max:30'],
            'product_url'     => ['required', 'url', 'max:2000'],
            'product_name'    => ['nullable', 'string', 'max:500'],
            'quantity'        => ['required', 'integer', 'min:1', 'max:99'],
            'declared_value'  => ['nullable', 'numeric', 'min:0'],
            'currency'        => ['nullable', 'string', 'max:8'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'size'            => ['nullable', 'string', 'max:30'],
            'color'           => ['nullable', 'string', 'max:100'],
        ]);

        // Identifier l'utilisateur par email
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'error' => 'Aucun compte associé à cet email. Veuillez vous inscrire sur Monrespro.',
                'code'  => 'user_not_found',
            ], 404);
        }

        $purchase = AssistedPurchase::query()->create([
            'user_id'         => $user->id,
            'product_url'     => $data['product_url'],
            'product_name'    => $data['product_name'] ?? null,
            'quantity'        => $data['quantity'],
            'declared_value'  => $data['declared_value'] ?? null,
            'currency'        => $data['currency'] ?? 'USD',
            'size'            => $data['size'] ?? null,
            'color'           => $data['color'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'status'          => AssistedPurchaseStatus::PENDING_QUOTE,
            'reference_code'  => AssistedPurchase::generateReferenceCode(),
            'source'          => 'wordpress',
        ]);

        return response()->json([
            'message'        => 'Votre demande d\'achat assisté a été enregistrée. Notre équipe vous contactera sous 24h.',
            'reference_code' => $purchase->reference_code,
        ], 201);
    }

    /**
     * §17.2 — Création d'une pré-alerte depuis WordPress.
     * Le client s'identifie par email + numéro de locker.
     */
    public function createPreAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'                    => ['required', 'email', 'max:255'],
            'locker_number'            => ['required', 'string', 'max:30'],
            'vendor_tracking_number'   => ['required', 'string', 'max:255'],
            'carrier_name'             => ['required', 'string', 'max:255'],
            'merchant_name'            => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:500'],
            'declared_value'           => ['nullable', 'numeric', 'min:0'],
            'value_currency'           => ['nullable', 'string', 'max:8'],
            'estimated_arrival_date'   => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string', 'max:2000'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->where('locker_number', $data['locker_number'])
            ->first();

        if (! $user) {
            return response()->json([
                'error' => 'Email ou numéro de casier incorrect.',
                'code'  => 'auth_failed',
            ], 401);
        }

        $preAlert = PreAlert::query()->create([
            'user_id'                  => $user->id,
            'locker_id'                => $user->locker?->id,
            'reference_code'           => PreAlert::generateReferenceCode(),
            'vendor_tracking_number'   => $data['vendor_tracking_number'],
            'carrier_name'             => $data['carrier_name'],
            'merchant_name'            => $data['merchant_name'] ?? null,
            'description'              => $data['description'] ?? null,
            'declared_value'           => $data['declared_value'] ?? null,
            'value_currency'           => $data['value_currency'] ?? 'EUR',
            'estimated_arrival_date'   => $data['estimated_arrival_date'] ?? null,
            'notes'                    => $data['notes'] ?? null,
            'status'                   => ShipmentStatus::PendingDropOff,
            'source'                   => 'wordpress',
        ]);

        return response()->json([
            'message'        => 'Votre pré-alerte a été enregistrée. Elle sera visible dans votre espace client.',
            'reference_code' => $preAlert->reference_code,
        ], 201);
    }
}

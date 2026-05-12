<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Models\AssistedPurchase;
use App\Models\QuoteSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteResponseController extends Controller
{
    public function verifyToken(Request $request): JsonResponse
    {
        $token = $request->get('token');

        if (!$token) {
            return response()->json(['message' => 'Token manquant.'], 400);
        }

        $snapshot = QuoteSnapshot::where('response_token', $token)->first();

        if (!$snapshot) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 404);
        }

        $purchase = $snapshot->assistedPurchase;
        $isExpired = $snapshot->isExpired();

        if ($snapshot->client_response !== 'pending') {
            return response()->json([
                'status' => 'already_responded',
                'response' => $snapshot->client_response,
                'responded_at' => $snapshot->responded_at?->toIso8601String(),
            ]);
        }

        return response()->json([
            'status' => $isExpired ? 'expired' : 'valid',
            'quote' => [
                'reference' => $purchase->id,
                'total' => $snapshot->total_primary,
                'currency' => $snapshot->primary_currency,
                'total_secondary' => $snapshot->total_secondary,
                'secondary_currency' => $snapshot->secondary_currency,
                'estimated_delivery' => $snapshot->estimated_delivery,
                'staff_message' => $snapshot->staff_message,
                'expires_at' => $snapshot->expires_at?->toIso8601String(),
                'version' => $snapshot->version,
                'articles' => $snapshot->articles_data,
            ],
        ]);
    }

    public function respond(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'response' => ['required', 'in:accepted,refused'],
            'refusal_reason' => ['required_if:response,refused', 'nullable', 'string', 'max:100'],
            'refusal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $snapshot = QuoteSnapshot::where('response_token', $data['token'])->first();

        if (!$snapshot) {
            return response()->json(['message' => 'Lien invalide.'], 404);
        }

        if ($snapshot->client_response !== 'pending') {
            return response()->json(['message' => 'Réponse déjà enregistrée.'], 422);
        }

        if ($snapshot->isExpired()) {
            return response()->json(['message' => 'Devis expiré.'], 422);
        }

        $snapshot->update([
            'client_response' => $data['response'],
            'refusal_reason' => $data['refusal_reason'] ?? null,
            'refusal_note' => $data['refusal_note'] ?? null,
            'responded_at' => now(),
        ]);

        $purchase = $snapshot->assistedPurchase;
        $oldStatus = $purchase->status;

        if ($data['response'] === 'accepted') {
            $purchase->update([
                'status' => AssistedPurchaseStatus::AWAITING_PAYMENT,
                'reminder_count' => 99,
            ]);
            event(new AssistedPurchaseStatusChanged(
                $purchase, $oldStatus, AssistedPurchaseStatus::AWAITING_PAYMENT
            ));
        } else {
            $purchase->update([
                'status' => AssistedPurchaseStatus::CANCELLED,
                'refusal_reason' => $data['refusal_reason'],
                'refusal_note' => $data['refusal_note'] ?? null,
                'reminder_count' => 99,
            ]);
            event(new AssistedPurchaseStatusChanged(
                $purchase, $oldStatus, AssistedPurchaseStatus::CANCELLED
            ));
        }

        return response()->json([
            'message' => $data['response'] === 'accepted'
                ? 'Devis accepté. Vous allez recevoir les instructions de paiement.'
                : 'Refus enregistré. Merci de votre retour.',
            'response' => $data['response'],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AssistedPurchase;
use App\Models\LedgerEntry;
use App\Models\PaymentProof;
use App\Models\Shipment;
use App\Services\Integrations\FlexPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * §15 — FlexPay phase 2 : parcours transactionnel bout en bout.
 */
class FlexPayController extends Controller
{
    public function __construct(private FlexPayService $flexPay) {}

    /**
     * Initie un paiement Mobile Money via FlexPay pour une expédition ou un achat assisté.
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        abort_unless($this->flexPay->isEnabled(), 503, 'FlexPay non configuré.');

        $data = $request->validate([
            'payable_type' => ['required', 'string', 'in:shipment,assisted_purchase'],
            'payable_id' => ['required', 'integer'],
            'phone' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        $user = $request->user();
        $reference = 'MRP-' . strtoupper(Str::random(8));

        $result = $this->flexPay->initiatePayment([
            'phone' => $data['phone'],
            'reference' => $reference,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'CDF',
        ]);

        if (! $result || ($result['code'] ?? '') !== '0') {
            return response()->json([
                'message' => 'Échec de l\'initiation du paiement FlexPay.',
                'details' => $result,
            ], 422);
        }

        // Créer un PaymentProof en attente
        PaymentProof::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'CDF',
            'payment_method' => 'flexpay',
            'reference' => $reference,
            'order_number' => $result['orderNumber'] ?? null,
            'status' => 'pending',
            'notes' => "FlexPay initié — {$data['payable_type']} #{$data['payable_id']}",
        ]);

        return response()->json([
            'message' => 'Paiement initié. Approuvez la transaction sur votre téléphone.',
            'reference' => $reference,
            'order_number' => $result['orderNumber'] ?? null,
        ]);
    }

    /**
     * Vérifie le statut d'un paiement FlexPay.
     */
    public function checkStatus(Request $request, string $orderNumber): JsonResponse
    {
        abort_unless($this->flexPay->isEnabled(), 503, 'FlexPay non configuré.');

        $result = $this->flexPay->checkStatus($orderNumber);

        if (! $result) {
            return response()->json(['message' => 'Impossible de vérifier le statut.'], 422);
        }

        $success = ($result['code'] ?? '') === '0';

        if ($success) {
            // Mettre à jour le PaymentProof correspondant
            $proof = PaymentProof::where('order_number', $orderNumber)->first();
            if ($proof && $proof->status === 'pending') {
                $proof->update(['status' => 'approved', 'validated_at' => now()]);

                LedgerEntry::create([
                    'type' => 'payment',
                    'amount' => (float) $proof->amount,
                    'currency' => $proof->currency,
                    'description' => "Paiement FlexPay — {$proof->reference}",
                    'reference_type' => PaymentProof::class,
                    'reference_id' => $proof->id,
                    'user_id' => $proof->user_id,
                    'agency_id' => $proof->agency_id,
                ]);
            }
        }

        return response()->json([
            'success' => $success,
            'status' => $result,
        ]);
    }

    /**
     * Webhook de callback FlexPay (déjà routé vers WebhookController, mais logique ici).
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $result = $this->flexPay->processWebhook($payload);

        if ($result['success']) {
            $proof = PaymentProof::where('order_number', $result['order_number'])
                ->orWhere('reference', $result['reference'])
                ->first();

            if ($proof && $proof->status === 'pending') {
                $proof->update(['status' => 'approved', 'validated_at' => now()]);

                LedgerEntry::create([
                    'type' => 'payment',
                    'amount' => (float) $proof->amount,
                    'currency' => $proof->currency,
                    'description' => "Paiement FlexPay webhook — {$proof->reference}",
                    'reference_type' => PaymentProof::class,
                    'reference_id' => $proof->id,
                    'user_id' => $proof->user_id,
                    'agency_id' => $proof->agency_id,
                ]);
            }
        }

        return response()->json(['received' => true]);
    }
}

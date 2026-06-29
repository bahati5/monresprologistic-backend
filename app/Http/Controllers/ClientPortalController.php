<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\Shipment;
use App\Models\Setting;
use App\Support\ClientInAppNotificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPortalController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $shipmentsInTransit = Shipment::where('creator_user_id', $user->id)
            ->excludingDrafts()
            ->whereNotIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value])
            ->count();

        $purchasesActive = AssistedPurchase::where('user_id', $user->id)
            ->whereNotIn('status', [
                AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT->value,
                AssistedPurchaseStatus::CANCELLED->value,
            ])->count();

        $packagesAtHub = CustomerPackage::where('user_id', $user->id)
            ->where('status', ShipmentStatus::ReceivedAtHub->value)
            ->count();

        $pendingActions = AssistedPurchase::where('user_id', $user->id)
            ->where('status', AssistedPurchaseStatus::AWAITING_PAYMENT->value)
            ->count();

        $recentActivity = Shipment::where('creator_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'public_tracking', 'status', 'updated_at']);

        // §11.3 — Alertes prioritaires : devis en attente, actions requises
        $priorityAlerts = $this->buildPriorityAlerts($user);

        return response()->json([
            'kpis' => [
                'shipments_in_transit' => $shipmentsInTransit,
                'purchases_active' => $purchasesActive,
                'packages_at_hub' => $packagesAtHub,
                'pending_actions' => $pendingActions,
            ],
            'recent_activity' => $recentActivity,
            'priority_alerts' => $priorityAlerts,
        ]);
    }

    public function locker(Request $request): JsonResponse
    {
        $user = $request->user();
        $locker = $user->locker;

        $packages = CustomerPackage::where('user_id', $user->id)
            ->where('status', ShipmentStatus::ReceivedAtHub->value)
            ->orderByDesc('received_at')
            ->get();

        return response()->json([
            'locker' => $locker,
            'packages' => $packages,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::whereHas('shipment', fn ($q) => $q->where('creator_user_id', $user->id))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'invoices' => $invoices,
        ]);
    }

    /**
     * §18.3 — Retourne les préférences de notification du client.
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $prefs = $user->notification_preferences ?? [
            'sms' => true,
            'email' => true,
            'in_app' => true,
            'categories' => [],
        ];

        return response()->json(['preferences' => $prefs]);
    }

    /**
     * §18.3 — Met à jour les préférences de notification du client.
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms' => ['boolean'],
            'email' => ['boolean'],
            'in_app' => ['boolean'],
            'categories' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $current = $user->notification_preferences ?? [];
        $merged = array_merge($current, $data);

        $user->update(['notification_preferences' => $merged]);

        return response()->json(['message' => 'Préférences mises à jour.', 'preferences' => $merged]);
    }

    /**
     * §11.3 — Construit la liste des alertes prioritaires pour le portail.
     */
    private function buildPriorityAlerts(\App\Models\User $user): array
    {
        $alerts = [];

        // Devis en attente de réponse
        $quotedPurchases = AssistedPurchase::where('user_id', $user->id)
            ->where('status', AssistedPurchaseStatus::QUOTED->value)
            ->get(['id', 'quoted_at']);

        foreach ($quotedPurchases as $purchase) {
            $expiryHours = (int) Setting::getValue('quote_expiry_hours', 72);
            $expiresAt = $purchase->quoted_at?->addHours($expiryHours);
            $alerts[] = [
                'type' => 'quote_pending',
                'severity' => 'warning',
                'title' => 'Devis en attente de votre réponse',
                'message' => 'Un devis vous attend. ' . ($expiresAt ? 'Expire le ' . $expiresAt->format('d/m/Y à H:i') . '.' : ''),
                'action_url' => ClientInAppNotificationLinks::clientSpaFromInternalPath("/purchase-orders/{$purchase->id}"),
                'action_label' => 'Voir le devis',
            ];
        }

        // Paiements en attente de validation
        $awaitingPayment = AssistedPurchase::where('user_id', $user->id)
            ->where('status', AssistedPurchaseStatus::AWAITING_PAYMENT->value)
            ->count();

        if ($awaitingPayment > 0) {
            $alerts[] = [
                'type' => 'payment_pending',
                'severity' => 'info',
                'title' => "{$awaitingPayment} paiement(s) à effectuer",
                'message' => 'Des achats assistés attendent votre preuve de paiement.',
                'action_url' => ClientInAppNotificationLinks::clientSpaFromInternalPath('/purchase-orders'),
                'action_label' => 'Mes achats',
            ];
        }

        return $alerts;
    }
}


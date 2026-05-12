<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\QuoteSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteDashboardController extends Controller
{
    public function metrics(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $user->agency_id;

        $baseQuery = AssistedPurchase::query();
        if (!$user->canAccessAllAgencies()) {
            $baseQuery->whereHas('user', fn ($q) => $q->where('agency_id', $agencyId));
        }

        $openStatuses = [
            AssistedPurchaseStatus::QUOTED->value,
            AssistedPurchaseStatus::AWAITING_PAYMENT->value,
        ];

        $openQuotes = (clone $baseQuery)
            ->whereIn('status', $openStatuses)
            ->count();

        $pendingValue = (clone $baseQuery)
            ->whereIn('status', $openStatuses)
            ->sum('total_amount');

        $totalQuoted = (clone $baseQuery)
            ->whereNotNull('quoted_at')
            ->count();

        $totalAccepted = (clone $baseQuery)
            ->whereIn('status', [
                AssistedPurchaseStatus::PAID->value,
                AssistedPurchaseStatus::ORDERED->value,
                AssistedPurchaseStatus::ARRIVED_AT_HUB->value,
                AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT->value,
            ])
            ->count();

        $acceptanceRate = $totalQuoted > 0
            ? round(($totalAccepted / $totalQuoted) * 100, 1)
            : 0;

        $avgResponseHours = QuoteSnapshot::whereNotNull('responded_at')
            ->whereNotNull('sent_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, sent_at, responded_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        return response()->json([
            'open_quotes' => $openQuotes,
            'pending_value' => round((float) $pendingValue, 2),
            'acceptance_rate' => $acceptanceRate,
            'avg_response_hours' => round((float) $avgResponseHours, 1),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $user = $request->user();
        $tab = $request->get('tab', 'all');

        $query = AssistedPurchase::with(['user:id,name,email', 'items'])
            ->whereNotNull('quoted_at');

        if (!$user->canAccessAllAgencies()) {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        switch ($tab) {
            case 'pending':
                $query->where('status', AssistedPurchaseStatus::QUOTED)
                    ->where('reminder_count', 0);
                break;
            case 'reminded':
                $query->where('status', AssistedPurchaseStatus::QUOTED)
                    ->where('reminder_count', '>', 0);
                break;
            case 'expired':
                $query->where('status', AssistedPurchaseStatus::EXPIRED);
                break;
            case 'accepted':
                $query->whereIn('status', [
                    AssistedPurchaseStatus::AWAITING_PAYMENT,
                    AssistedPurchaseStatus::PAID,
                    AssistedPurchaseStatus::ORDERED,
                ]);
                break;
        }

        $quotes = $query->orderByDesc('quoted_at')
            ->paginate($request->get('per_page', 20));

        return response()->json($quotes);
    }

    public function prolong(Request $request, AssistedPurchase $assistedPurchase): JsonResponse
    {
        $data = $request->validate([
            'additional_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        if ($assistedPurchase->status !== AssistedPurchaseStatus::QUOTED &&
            $assistedPurchase->status !== AssistedPurchaseStatus::EXPIRED) {
            return response()->json(['message' => 'Ce devis ne peut pas être prolongé.'], 422);
        }

        $currentExpiry = $assistedPurchase->quote_expires_at ?? now();
        $newExpiry = $currentExpiry->addDays($data['additional_days']);

        $assistedPurchase->update([
            'quote_expires_at' => $newExpiry,
            'status' => AssistedPurchaseStatus::QUOTED,
        ]);

        $latestSnapshot = QuoteSnapshot::where('assisted_purchase_id', $assistedPurchase->id)
            ->orderByDesc('version')
            ->first();

        if ($latestSnapshot) {
            $latestSnapshot->update([
                'expires_at' => $newExpiry,
                'client_response' => 'pending',
            ]);
        }

        return response()->json([
            'message' => 'Devis prolongé.',
            'new_expires_at' => $newExpiry->toIso8601String(),
        ]);
    }

    public function cancelReminders(Request $request, AssistedPurchase $assistedPurchase): JsonResponse
    {
        $assistedPurchase->update([
            'reminder_count' => 99,
        ]);

        return response()->json(['message' => 'Relances annulées.']);
    }
}

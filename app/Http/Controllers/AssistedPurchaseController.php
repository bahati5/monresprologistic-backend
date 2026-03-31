<?php

namespace App\Http\Controllers;

use App\Models\AssistedPurchase;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistedPurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = AssistedPurchase::query()->with(['user', 'operator', 'status'])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        return response()->json([
            'purchases' => $q->paginate(15),
        ]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['message' => 'OK']);
    }

    public function show(AssistedPurchase $assisted_purchase): JsonResponse
    {
        $assisted_purchase->load(['user', 'operator', 'status']);

        return response()->json([
            'purchase' => $assisted_purchase,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_url' => ['required', 'url', 'max:2000'],
            'size' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price_displayed' => ['nullable', 'numeric', 'min:0'],
            'price_currency' => ['nullable', 'string', 'max:8'],
        ]);

        $status = Status::query()->where('code', 'created')->first();

        AssistedPurchase::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'status_id' => $status?->id,
        ]);

        return response()->json(['message' => 'Demande d’achat assisté envoyée.']);
    }

    public function quote(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'quote_amount' => ['required', 'numeric', 'min:0'],
            'quote_currency' => ['required', 'string', 'max:8'],
        ]);

        $assisted_purchase->update([
            ...$data,
            'operator_id' => $request->user()->id,
            'quoted_at' => now(),
        ]);

        return response()->json(['message' => 'Devis enregistré.']);
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_assisted_purchases'),
            403
        );
    }
}

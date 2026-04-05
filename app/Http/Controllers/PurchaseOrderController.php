<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\CustomerPackage;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $q = PurchaseOrder::query()->with(['user', 'operator', 'items']);

        if ($isClient) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('purchase_orders.reference_code', 'like', $term)
                    ->orWhereHas('items', function ($iq) use ($term) {
                        $iq->where('description', 'like', $term)
                            ->orWhere('product_url', 'like', $term);
                    })
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        if ($request->filled('status')) {
            $q->where('purchase_orders.status', $request->string('status'));
        }

        if ($request->filled('date_from')) {
            $q->whereDate('purchase_orders.created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $q->whereDate('purchase_orders.created_at', '<=', $request->date('date_to'));
        }

        if (! $isClient && $request->filled('client')) {
            $clientTerm = '%'.$request->string('client').'%';
            $q->whereHas('user', fn ($u) => $u->where('name', 'like', $clientTerm));
        }

        $q->latest('purchase_orders.created_at');

        return response()->json([
            'orders' => $q->paginate(20)->withQueryString(),
            'statuses' => $this->purchaseOrderStatusOptions(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        return response()->json([
            'clients' => $isClient ? null : User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
            'isAdmin' => ! $isClient,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $data = $request->validate([
            'client_id' => [$isClient ? 'nullable' : 'required', 'integer', 'exists:users,id'],
            'cart_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_url' => ['required', 'url', 'max:2000'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.size' => ['nullable', 'string', 'max:64'],
            'items.*.color' => ['nullable', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_currency' => ['nullable', 'string', 'max:8'],
        ]);

        // Admin can create for any client, client creates for themselves
        $targetUserId = $isClient ? $user->id : $data['client_id'];

        $po = PurchaseOrder::query()->create([
            'reference_code' => PurchaseOrder::generateReferenceCode(),
            'user_id' => $targetUserId,
            'status' => 'draft',
            'cart_url' => $data['cart_url'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            $po->items()->create($item);
        }

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Ordre d\'achat soumis.');
    }

    public function edit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $user = $request->user();
        $purchaseOrder->load(['items', 'user']);

        return response()->json([
            'order' => $purchaseOrder,
            'clients' => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['user', 'operator', 'items', 'customerPackage']);

        return response()->json([
            'order' => $purchaseOrder,
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'cart_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_url' => ['required', 'url', 'max:2000'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.size' => ['nullable', 'string', 'max:64'],
            'items.*.color' => ['nullable', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_currency' => ['nullable', 'string', 'max:8'],
        ]);

        $purchaseOrder->update([
            'cart_url' => $data['cart_url'] ?? $purchaseOrder->cart_url,
            'notes' => $data['notes'] ?? $purchaseOrder->notes,
        ]);

        if (isset($data['items'])) {
            $purchaseOrder->items()->delete();
            foreach ($data['items'] as $item) {
                unset($item['id']);
                $purchaseOrder->items()->create($item);
            }
        }

        return back()->with('success', 'Ordre d\'achat mis à jour.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Ordre d\'achat supprimé.');
    }

    public function quote(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'quote_amount' => ['required', 'numeric', 'min:0'],
            'quote_currency' => ['required', 'string', 'max:8'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'local_shipping_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $total = ($data['quote_amount'] ?? 0)
            + ($data['commission_amount'] ?? 0)
            + ($data['local_shipping_fee'] ?? 0);

        $purchaseOrder->update([
            ...$data,
            'total_amount' => $total,
            'operator_id' => $request->user()->id,
            'quoted_at' => now(),
            'status' => 'quoted',
        ]);

        return response()->json(['message' => 'Devis envoyé au client.']);
    }

    public function markPaid(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $purchaseOrder->update([
            'paid_at' => now(),
            'status' => 'paid',
        ]);

        return response()->json(['message' => 'Paiement enregistré.']);
    }

    public function markPurchased(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $purchaseOrder->update([
            'purchased_at' => now(),
            'status' => 'purchasing',
        ]);

        return back()->with('success', 'Achat en cours d\'exécution.');
    }

    public function convert(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        $purchaseOrder->load('user.locker');

        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'condition_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pkg = CustomerPackage::query()->create([
            'reference_code' => CustomerPackage::generateReferenceCode(),
            'user_id' => $purchaseOrder->user_id,
            'agency_id' => $request->user()->agency_id,
            'locker_id' => $purchaseOrder->user?->locker?->id,
            'status' => ShipmentStatus::ReceivedAtHub,
            'description' => $purchaseOrder->items->pluck('description')->filter()->implode(', '),
            'weight_kg' => $data['weight_kg'],
            'length_cm' => $data['length_cm'] ?? null,
            'width_cm' => $data['width_cm'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'declared_value' => $purchaseOrder->total_amount,
            'value_currency' => $purchaseOrder->quote_currency ?? 'EUR',
            'received_at' => now(),
            'received_by' => $request->user()->id,
            'condition_notes' => $data['condition_notes'] ?? null,
        ]);

        $purchaseOrder->update([
            'received_at' => now(),
            'converted_customer_package_id' => $pkg->id,
            'status' => 'received',
        ]);

        return back()->with('success', 'Converti en colis — '.$pkg->reference_code);
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_purchase_orders')
                || $request->user()->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );
    }

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    private function purchaseOrderStatusOptions(): Collection
    {
        return collect([
            ['code' => 'draft', 'name' => 'Brouillon'],
            ['code' => 'quoted', 'name' => 'Devis envoyé'],
            ['code' => 'paid', 'name' => 'Payé'],
            ['code' => 'purchasing', 'name' => 'Achat en cours'],
            ['code' => 'received', 'name' => 'Réceptionné'],
            ['code' => 'cancelled', 'name' => 'Annulé'],
        ]);
    }
}

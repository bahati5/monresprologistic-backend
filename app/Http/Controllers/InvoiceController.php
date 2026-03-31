<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Invoice::query()->with(['user', 'shipment'])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
        }

        $shipmentsForInvoice = [];
        if ($user->can('manage_finances')) {
            $sq = Shipment::query()->with(['recipient', 'sender'])->latest()->limit(100);
            $this->scopeShipmentsFor($sq, $user);
            $shipmentsForInvoice = $sq->get();
        }

        return response()->json([
            'invoices' => $q->paginate(20),
            'shipmentsForInvoice' => $shipmentsForInvoice,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $data = $request->validate([
            'shipment_id' => ['required', 'exists:shipments,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
        ]);

        $shipment = Shipment::query()->findOrFail($data['shipment_id']);
        $this->authorize('view', $shipment);

        $number = 'INV-'.str_pad((string) (Invoice::query()->count() + 1), 6, '0', STR_PAD_LEFT);

        $billUserId = $shipment->sender_id ?? $shipment->senderClient?->user_id;
        abort_unless($billUserId, 403, 'Impossible de créer une facture : ce client n’a pas de compte portail.');

        Invoice::query()->create([
            'invoice_number' => $number,
            'user_id' => $billUserId,
            'shipment_id' => $shipment->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Facture créée.']);
    }
}

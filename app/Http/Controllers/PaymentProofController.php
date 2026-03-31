<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProofController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->can('approve_payments')) {
            $q = PaymentProof::query()->with(['invoice.shipment', 'paymentMethod'])->latest();
            if (! $user->canAccessAllAgencies()) {
                $q->whereHas('invoice.shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
            }

            return response()->json([
                'proofs' => $q->paginate(25),
            ]);
        }

        $q = PaymentProof::query()->with(['invoice', 'paymentMethod'])
            ->whereHas('invoice', fn ($i) => $i->where('user_id', $user->id))
            ->latest();

        return response()->json([
            'proofs' => $q->paginate(15),
            'openInvoices' => Invoice::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', 'paid')
                ->with('shipment')
                ->latest()
                ->get(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        abort_unless($invoice->user_id === $request->user()->id, 403);

        PaymentProof::query()->create([
            ...$data,
            'status' => 'pending',
        ]);

        $reviewStatus = Status::query()->where('code', 'financial_review')->first();
        if ($reviewStatus && $invoice->shipment) {
            $invoice->shipment->update(['status_id' => $reviewStatus->id]);
        }

        return response()->json(['message' => 'Preuve de paiement envoyée.']);
    }

    public function approve(Request $request, PaymentProof $paymentProof): JsonResponse
    {
        abort_unless($request->user()->can('approve_payments'), 403);

        $paymentProof->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $invoice = $paymentProof->invoice;
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        LedgerEntry::query()->create([
            'agency_id' => $invoice->shipment?->agency_id,
            'user_id' => $request->user()->id,
            'invoice_id' => $invoice->id,
            'amount' => $paymentProof->amount,
            'currency' => $invoice->currency,
            'type' => 'credit',
            'description' => 'Paiement approuvé',
        ]);

        $ready = Status::query()->where('code', 'ready_delivery')->first();
        if ($ready && $invoice->shipment) {
            $invoice->shipment->update(['status_id' => $ready->id]);
        }

        return response()->json(['message' => 'Paiement approuvé.']);
    }

    public function reject(Request $request, PaymentProof $paymentProof): JsonResponse
    {
        abort_unless($request->user()->can('approve_payments'), 403);

        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:1000']]);

        $paymentProof->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'reject_reason' => $data['reject_reason'],
        ]);

        return response()->json(['message' => 'Preuve rejetée.']);
    }
}

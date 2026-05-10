<?php

namespace App\Http\Controllers;

use App\Models\BillingExtra;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Shipment;
use App\Support\ReferenceNumberFormatter;
use App\Support\SequenceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public static function computeExtraLineAmount(float $base, string $type, float $value): float
    {
        return match ($type) {
            'fixed' => round(max($value, 0), 2),
            'percentage' => round(max($base, 0) * max($value, 0) / 100, 2),
            default => 0.0,
        };
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('client')) {
            // Portail : uniquement ses factures
        } elseif (! $user->can('manage_finances')) {
            abort(403, 'Liste des factures réservée au pôle finance.');
        }

        $q = Invoice::query()->with(['user', 'shipment', 'extraLines'])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
        }

        $shipmentsForInvoice = [];
        if ($user->can('manage_finances')) {
            $sq = Shipment::query()->with(['recipientProfile', 'senderProfile'])->latest()->limit(100);
            $this->scopeShipmentsForUser($sq, $user);
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
            'currency' => ['required', 'string', 'max:8'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_lines' => ['nullable', 'array'],
            'extra_lines.*.billing_extra_id' => ['nullable', 'integer', 'exists:billing_extras,id'],
            'extra_lines.*.label' => ['nullable', 'string', 'max:255'],
            'extra_lines.*.calculation_description' => ['nullable', 'string', 'max:2000'],
            'extra_lines.*.type' => ['nullable', 'in:percentage,fixed'],
            'extra_lines.*.value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $shipment = Shipment::query()->findOrFail($data['shipment_id']);
        $this->authorize('view', $shipment);

        $lockedStatuses = ['pending', 'paid', 'sent', 'partial', 'overdue'];
        $hasLockedInvoice = Invoice::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', $lockedStatuses)
            ->exists();
        if ($hasLockedInvoice) {
            throw ValidationException::withMessages([
                'shipment_id' => ['Une facture existe déjà pour cette expédition. Les montants figés ne peuvent pas être modifiés : utilisez un avoir ou une note de crédit.'],
            ]);
        }

        $billUserId = $shipment->creator_user_id ?? $shipment->senderProfile?->user?->id;
        abort_unless($billUserId, 403, 'Impossible de créer une facture : ce client n\'a pas de compte portail.');

        $extraLines = $data['extra_lines'] ?? [];
        $base = isset($data['base_amount']) ? (float) $data['base_amount'] : null;

        if (count($extraLines) > 0) {
            if ($base === null) {
                throw ValidationException::withMessages([
                    'base_amount' => 'Montant de base requis lorsque des extras sont ajoutés.',
                ]);
            }

            $linesPayload = [];
            $sort = 0;
            foreach ($extraLines as $line) {
                $bid = isset($line['billing_extra_id']) ? (int) $line['billing_extra_id'] : 0;
                $label = isset($line['label']) ? trim((string) $line['label']) : '';
                $calcDesc = $line['calculation_description'] ?? null;
                $type = $line['type'] ?? null;
                $value = isset($line['value']) ? (float) $line['value'] : null;

                if ($bid > 0) {
                    $be = BillingExtra::query()->find($bid);
                    if ($be) {
                        $label = $be->label;
                        $calcDesc = $be->calculation_description;
                        $type = $be->type;
                        $value = (float) $be->value;
                    }
                }

                if ($label === '' || $type === null || $value === null) {
                    throw ValidationException::withMessages([
                        'extra_lines' => 'Chaque extra doit avoir un libellé, un type et une valeur (ou un ID catalogue valide).',
                    ]);
                }

                $amt = self::computeExtraLineAmount($base, $type, $value);
                $linesPayload[] = [
                    'billing_extra_id' => $bid > 0 ? $bid : null,
                    'label' => $label,
                    'calculation_description' => $calcDesc,
                    'type' => $type,
                    'value' => $value,
                    'amount' => $amt,
                    'sort_order' => $sort++,
                ];
            }

            $totalExtras = array_sum(array_column($linesPayload, 'amount'));
            $finalAmount = round($base + $totalExtras, 2);
        } else {
            $finalAmount = isset($data['amount']) ? (float) $data['amount'] : null;
            if ($finalAmount === null) {
                throw ValidationException::withMessages([
                    'amount' => 'Montant requis si aucun extra n’est renseigné.',
                ]);
            }
            $linesPayload = [];
        }

        DB::transaction(function () use ($billUserId, $shipment, $finalAmount, $base, $data, $linesPayload) {
            $format = trim((string) (Setting::getValue('finance_invoice_format', '{prefix}-{seq}') ?? ''));
            if ($format === '') {
                $format = '{prefix}-{seq}';
            }
            $prefix = trim((string) (Setting::getValue('finance_invoice_prefix', 'INV') ?? 'INV')) ?: 'INV';
            $pad = max(1, min(12, (int) (Setting::getValue('finance_invoice_seq_pad', '6') ?: 6)));

            do {
                $seq = SequenceSetting::allocateNext('finance_invoice_next_seq', 1);
                $seqPadded = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
                $now = now();
                $number = ReferenceNumberFormatter::apply($format, array_merge(
                    ReferenceNumberFormatter::localeAndCalendarReplacements($now),
                    [
                        'prefix' => $prefix,
                        'year' => $now->format('Y'),
                        'month' => $now->format('m'),
                        'day' => $now->format('d'),
                        'seq' => $seqPadded,
                    ],
                ));
            } while (Invoice::query()->where('invoice_number', $number)->exists());

            $invoice = Invoice::query()->create([
                'invoice_number' => $number,
                'user_id' => $billUserId,
                'shipment_id' => $shipment->id,
                'agency_id' => $shipment->agency_id,
                'amount' => $finalAmount,
                'base_amount' => count($linesPayload) > 0 ? $base : null,
                'currency' => $data['currency'],
                'status' => 'pending',
            ]);

            foreach ($linesPayload as $lp) {
                $invoice->extraLines()->create($lp);
            }
        });

        return response()->json(['message' => 'Facture créée.']);
    }

    /**
     * Catalogue d’extras pour l’écran facturation (sans permission paramètres).
     */
    public function billingExtrasCatalog(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        return response()->json([
            'billing_extras' => BillingExtra::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /**
     * Création rapide d’un extra depuis la facturation.
     */
    public function storeBillingExtra(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $data = $request->validate([
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'label' => ['required', 'string', 'max:255'],
            'calculation_description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        BillingExtra::create($data);

        return response()->json(['message' => 'Extra créé.']);
    }
}

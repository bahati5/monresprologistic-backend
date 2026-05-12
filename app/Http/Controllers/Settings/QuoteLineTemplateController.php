<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\ResolvesQuoteAgency;
use App\Http\Controllers\Controller;
use App\Models\QuoteAuditLog;
use App\Models\QuoteLineTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteLineTemplateController extends Controller
{
    use ResolvesQuoteAgency;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        $templates = QuoteLineTemplate::forAgency($agencyId)
            ->ordered()
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $templates = QuoteLineTemplate::forAgency($this->quoteAgencyId($request))
            ->active()
            ->ordered()
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'internal_code' => [
                'required', 'string', 'max:50',
                Rule::unique('quote_line_templates')->where('agency_id', $agencyId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in(['percentage', 'fixed_amount', 'manual'])],
            'calculation_base' => ['nullable', 'required_if:type,percentage', Rule::in(['product_price', 'subtotal_after_commission'])],
            'default_value' => ['nullable', 'numeric', 'min:0'],
            'is_mandatory' => ['boolean'],
            'is_visible_to_client' => ['boolean'],
            'is_active' => ['boolean'],
            'display_order' => ['integer', 'min:0'],
            'applies_to' => [Rule::in(['all', 'assisted_purchase', 'shipment'])],
            'behavior' => [Rule::in(['mandatory', 'optional', 'optional_included'])],
        ]);

        $activeCount = QuoteLineTemplate::forAgency($agencyId)->active()->count();
        $isActive = $data['is_active'] ?? true;

        if ($isActive && $activeCount >= 20) {
            return response()->json([
                'message' => 'Limite de 20 lignes actives atteinte.',
            ], 422);
        }

        $data['agency_id'] = $agencyId;
        $template = QuoteLineTemplate::create($data);

        QuoteAuditLog::record(
            $agencyId,
            'quote_line_template',
            $template->id,
            $template->name,
            'created',
            $data,
            $user->id,
        );

        return response()->json(['template' => $template], 201);
    }

    public function update(Request $request, QuoteLineTemplate $quoteLineTemplate): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        if ($quoteLineTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'internal_code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('quote_line_templates')
                    ->where('agency_id', $agencyId)
                    ->ignore($quoteLineTemplate->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', Rule::in(['percentage', 'fixed_amount', 'manual'])],
            'calculation_base' => ['nullable', Rule::in(['product_price', 'subtotal_after_commission'])],
            'default_value' => ['nullable', 'numeric', 'min:0'],
            'is_mandatory' => ['boolean'],
            'is_visible_to_client' => ['boolean'],
            'is_active' => ['boolean'],
            'display_order' => ['integer', 'min:0'],
            'applies_to' => [Rule::in(['all', 'assisted_purchase', 'shipment'])],
            'behavior' => [Rule::in(['mandatory', 'optional', 'optional_included'])],
        ]);

        if (isset($data['is_active']) && $data['is_active'] && ! $quoteLineTemplate->is_active) {
            $activeCount = QuoteLineTemplate::forAgency($agencyId)->active()->count();
            if ($activeCount >= 20) {
                return response()->json([
                    'message' => 'Limite de 20 lignes actives atteinte.',
                ], 422);
            }
        }

        $original = $quoteLineTemplate->only(array_keys($data));
        $quoteLineTemplate->update($data);

        $changes = [];
        foreach ($data as $key => $value) {
            if (($original[$key] ?? null) != $value) {
                $changes[$key] = ['from' => $original[$key] ?? null, 'to' => $value];
            }
        }

        if (! empty($changes)) {
            QuoteAuditLog::record(
                $agencyId,
                'quote_line_template',
                $quoteLineTemplate->id,
                $quoteLineTemplate->name,
                'updated',
                $changes,
                $user->id,
            );
        }

        return response()->json(['template' => $quoteLineTemplate->fresh()]);
    }

    public function destroy(Request $request, QuoteLineTemplate $quoteLineTemplate): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        if ($quoteLineTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        if ($quoteLineTemplate->is_mandatory) {
            return response()->json([
                'message' => 'Impossible de supprimer une ligne obligatoire. Désactivez-la plutôt.',
            ], 422);
        }

        QuoteAuditLog::record(
            $agencyId,
            'quote_line_template',
            $quoteLineTemplate->id,
            $quoteLineTemplate->name,
            'deleted',
            null,
            $user->id,
        );

        $quoteLineTemplate->delete();

        return response()->json(['message' => 'Ligne supprimée.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer', 'exists:quote_line_templates,id'],
            'order.*.display_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data['order'] as $item) {
            QuoteLineTemplate::where('id', $item['id'])
                ->where('agency_id', $agencyId)
                ->update(['display_order' => $item['display_order']]);
        }

        QuoteAuditLog::record(
            $agencyId,
            'quote_line_template',
            0,
            'Réordonnancement',
            'reordered',
            ['order' => collect($data['order'])->pluck('id')->toArray()],
            $user->id,
        );

        return response()->json(['message' => 'Ordre mis à jour.']);
    }
}

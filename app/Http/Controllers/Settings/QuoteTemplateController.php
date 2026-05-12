<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\ResolvesQuoteAgency;
use App\Http\Controllers\Controller;
use App\Models\QuoteAuditLog;
use App\Models\QuoteTemplate;
use App\Models\QuoteTemplateLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteTemplateController extends Controller
{
    use ResolvesQuoteAgency;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $templates = QuoteTemplate::forAgency($this->quoteAgencyId($request))
            ->with(['lines.lineTemplate', 'creator:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_shared' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quote_line_template_id' => ['required', 'integer', 'exists:quote_line_templates,id'],
            'lines.*.custom_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sort_order' => ['integer', 'min:0'],
        ]);

        $template = QuoteTemplate::create([
            'agency_id' => $agencyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_shared' => $data['is_shared'] ?? false,
            'created_by' => $user->id,
        ]);

        foreach ($data['lines'] as $index => $line) {
            QuoteTemplateLine::create([
                'quote_template_id' => $template->id,
                'quote_line_template_id' => $line['quote_line_template_id'],
                'custom_value' => $line['custom_value'] ?? null,
                'sort_order' => $line['sort_order'] ?? $index,
            ]);
        }

        QuoteAuditLog::record(
            $agencyId,
            'quote_template',
            $template->id,
            $template->name,
            'created',
            ['lines_count' => count($data['lines'])],
            $user->id,
        );

        return response()->json([
            'template' => $template->load('lines.lineTemplate'),
        ], 201);
    }

    public function update(Request $request, QuoteTemplate $quoteTemplate): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        if ($quoteTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_shared' => ['boolean'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.quote_line_template_id' => ['required', 'integer', 'exists:quote_line_templates,id'],
            'lines.*.custom_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sort_order' => ['integer', 'min:0'],
        ]);

        $changes = [];
        $original = $quoteTemplate->only(['name', 'description', 'is_shared']);

        $quoteTemplate->update(collect($data)->only(['name', 'description', 'is_shared'])->toArray());

        foreach (['name', 'description', 'is_shared'] as $field) {
            if (isset($data[$field]) && ($original[$field] ?? null) != $data[$field]) {
                $changes[$field] = ['from' => $original[$field] ?? null, 'to' => $data[$field]];
            }
        }

        if (isset($data['lines'])) {
            $quoteTemplate->lines()->delete();
            foreach ($data['lines'] as $index => $line) {
                QuoteTemplateLine::create([
                    'quote_template_id' => $quoteTemplate->id,
                    'quote_line_template_id' => $line['quote_line_template_id'],
                    'custom_value' => $line['custom_value'] ?? null,
                    'sort_order' => $line['sort_order'] ?? $index,
                ]);
            }
            $changes['lines'] = ['from' => 'replaced', 'to' => count($data['lines']) . ' lines'];
        }

        if (! empty($changes)) {
            QuoteAuditLog::record(
                $agencyId,
                'quote_template',
                $quoteTemplate->id,
                $quoteTemplate->name,
                'updated',
                $changes,
                $user->id,
            );
        }

        return response()->json([
            'template' => $quoteTemplate->fresh()->load('lines.lineTemplate'),
        ]);
    }

    public function destroy(Request $request, QuoteTemplate $quoteTemplate): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->quoteAgencyId($request);

        if ($quoteTemplate->agency_id !== $agencyId) {
            abort(403);
        }

        QuoteAuditLog::record(
            $agencyId,
            'quote_template',
            $quoteTemplate->id,
            $quoteTemplate->name,
            'deleted',
            null,
            $user->id,
        );

        $quoteTemplate->delete();

        return response()->json(['message' => 'Template supprimé.']);
    }
}

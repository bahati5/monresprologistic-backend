<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'rules' => PricingRule::query()->with(['agency', 'zone'])->orderByDesc('priority')->get(),
            'agencies' => Agency::query()->where('is_active', true)->get(),
            'zones' => Zone::query()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'formula' => ['required', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        PricingRule::query()->create([...$data, 'is_active' => true]);

        return response()->json(['message' => 'Règle créée.']);
    }

    public function destroy(PricingRule $pricingRule): JsonResponse
    {
        $pricingRule->delete();

        return response()->json(['message' => 'Règle supprimée.']);
    }
}

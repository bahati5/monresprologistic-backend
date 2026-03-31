<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'taxes' => Tax::with('agency')->orderBy('sort_order')->get(),
            'agencies' => Agency::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        Tax::create($data);

        return response()->json(['message' => 'Taxe créée.']);
    }

    public function destroy(Tax $tax): JsonResponse
    {
        $tax->delete();

        return response()->json(['message' => 'Taxe supprimée.']);
    }
}

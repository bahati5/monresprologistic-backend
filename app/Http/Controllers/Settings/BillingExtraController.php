<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\BillingExtra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingExtraController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'billing_extras' => BillingExtra::with('agency')->orderBy('sort_order')->get(),
            'agencies' => Agency::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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

    public function update(Request $request, BillingExtra $billingExtra): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'label' => ['sometimes', 'string', 'max:255'],
            'calculation_description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $billingExtra->update($data);

        return response()->json(['message' => 'Extra mis à jour.']);
    }

    public function destroy(BillingExtra $billingExtra): JsonResponse
    {
        $billingExtra->delete();

        return response()->json(['message' => 'Extra supprimé.']);
    }
}

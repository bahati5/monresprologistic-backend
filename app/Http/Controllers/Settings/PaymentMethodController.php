<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'methods' => PaymentMethod::query()->with('agency')->orderBy('sort_order')->get(),
            'agencies' => Agency::query()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        PaymentMethod::query()->create([...$data, 'sort_order' => 0, 'is_active' => true]);

        return response()->json(['message' => 'Moyen de paiement ajouté.']);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return response()->json(['message' => 'Supprimé.']);
    }
}

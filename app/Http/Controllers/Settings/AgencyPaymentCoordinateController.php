<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyPaymentCoordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgencyPaymentCoordinateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'coordinates' => AgencyPaymentCoordinate::with('agency')->orderBy('sort_order')->get(),
            'agencies' => Agency::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'label' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        AgencyPaymentCoordinate::create($data);

        return response()->json(['message' => 'Coordonnées de paiement créées.']);
    }

    public function destroy(AgencyPaymentCoordinate $agencyPaymentCoordinate): JsonResponse
    {
        $agencyPaymentCoordinate->delete();

        return response()->json(['message' => 'Coordonnées de paiement supprimées.']);
    }
}

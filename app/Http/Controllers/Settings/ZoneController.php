<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'zones' => Zone::query()->with('agency')->get(),
            'agencies' => Agency::query()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'polygon' => ['nullable', 'array'],
        ]);

        Zone::query()->create([...$data, 'is_active' => true]);

        return response()->json(['message' => 'Zone créée.']);
    }

    public function destroy(Zone $zone): JsonResponse
    {
        $zone->delete();

        return response()->json(['message' => 'Zone supprimée.']);
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ShipLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipLineController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'shipLines' => ShipLine::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        ShipLine::create($data);

        return back()->with('success', 'Ligne d\'expédition créée.');
    }

    public function update(Request $request, ShipLine $shipLine): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $shipLine->update($data);

        return back()->with('success', 'Ligne d\'expédition mise à jour.');
    }

    public function destroy(ShipLine $shipLine): JsonResponse
    {
        $shipLine->delete();

        return back()->with('success', 'Ligne d\'expédition supprimée.');
    }
}

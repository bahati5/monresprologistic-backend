<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PackagingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'packagingTypes' => PackagingType::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'is_billable' => ['sometimes', 'boolean'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['is_billable'] = (bool) ($data['is_billable'] ?? false);
        if (! $data['is_billable']) {
            $data['unit_price'] = 0;
        }

        PackagingType::create($data);

        return response()->json(['message' => 'Type d\'emballage créé.']);
    }

    public function update(Request $request, PackagingType $packagingType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'is_billable' => ['sometimes', 'boolean'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['is_billable'] = (bool) ($data['is_billable'] ?? false);
        if (! $data['is_billable']) {
            $data['unit_price'] = 0;
        }

        $packagingType->update($data);

        return response()->json(['message' => 'Type d\'emballage mis à jour.']);
    }

    public function destroy(PackagingType $packagingType): JsonResponse
    {
        $packagingType->delete();

        return response()->json(['message' => 'Type d\'emballage supprimé.']);
    }
}

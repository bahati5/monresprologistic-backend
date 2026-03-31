<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'offices' => Office::with('agency')->orderBy('name')->get(),
            'agencies' => Agency::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'type' => ['required', 'in:office,branch'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        Office::create($data);

        return response()->json(['message' => 'Bureau créé.']);
    }

    public function update(Request $request, Office $office): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:office,branch'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $office->update($data);

        return response()->json(['message' => 'Bureau mis à jour.']);
    }

    public function destroy(Office $office): JsonResponse
    {
        $office->delete();

        return response()->json(['message' => 'Bureau supprimé.']);
    }
}

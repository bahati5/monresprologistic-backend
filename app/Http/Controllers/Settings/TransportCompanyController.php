<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TransportCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportCompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'companies' => TransportCompany::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['boolean'],
        ]);

        TransportCompany::create($data);

        return response()->json(['message' => 'Société de transport créée.']);
    }

    public function update(Request $request, TransportCompany $transportCompany): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['boolean'],
        ]);

        $transportCompany->update($data);

        return response()->json(['message' => 'Société de transport mise à jour.']);
    }

    public function destroy(TransportCompany $transportCompany): JsonResponse
    {
        $transportCompany->delete();

        return response()->json(['message' => 'Société de transport supprimée.']);
    }
}

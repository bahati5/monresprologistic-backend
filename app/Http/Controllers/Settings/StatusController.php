<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'statuses' => Status::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:statuses,code'],
            'name_fr' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'color_hex' => ['nullable', 'string', 'max:16'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        Status::query()->create([
            'code' => $data['code'],
            'name' => ['fr' => $data['name_fr'], 'en' => $data['name_en'] ?? $data['name_fr']],
            'color_hex' => $data['color_hex'] ?? '#64748b',
            'icon' => $data['icon'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Statut créé.']);
    }

    public function update(Request $request, Status $status): JsonResponse
    {
        $data = $request->validate([
            'name_fr' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'color_hex' => ['nullable', 'string', 'max:16'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $status->update([
            'name' => ['fr' => $data['name_fr'], 'en' => $data['name_en'] ?? $data['name_fr']],
            'color_hex' => $data['color_hex'] ?? $status->color_hex,
            'icon' => $data['icon'] ?? $status->icon,
            'sort_order' => $data['sort_order'] ?? $status->sort_order,
            'is_active' => $data['is_active'] ?? $status->is_active,
        ]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    public function destroy(Status $status): JsonResponse
    {
        $status->delete();

        return response()->json(['message' => 'Statut supprimé.']);
    }
}

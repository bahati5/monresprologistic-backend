<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'agencies' => Agency::query()->withCount('users')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:agencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'default_currency' => ['required', 'string', 'max:8'],
        ]);

        Agency::query()->create([
            ...$data,
            'slug' => Str::slug($data['name']).'-'.Str::random(4),
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Agence créée.']);
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_currency' => ['required', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        $agency->update($data);

        return response()->json(['message' => 'Agence mise à jour.']);
    }
}

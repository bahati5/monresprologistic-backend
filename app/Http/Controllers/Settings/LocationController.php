<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'countries' => Country::withCount('states')->orderBy('name')->get(),
            'states' => State::with('country')->withCount('cities')->orderBy('name')->get(),
            'cities' => City::with('state.country')->orderBy('name')->limit(200)->get(),
        ]);
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:3', 'unique:countries,code'],
            'iso2' => ['nullable', 'string', 'max:3'],
            'emoji' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]);

        $code = strtoupper($data['code']);
        $iso2 = strtoupper($data['iso2'] ?? $code);

        Country::create([
            'name' => $data['name'],
            'code' => $code,
            'iso2' => strlen($iso2) >= 2 ? $iso2 : $code,
            'emoji' => $data['emoji'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        Cache::forget('phone_countries');

        return response()->json(['message' => 'Pays créé.']);
    }

    public function destroyCountry(Country $country): JsonResponse
    {
        $country->delete();

        return response()->json(['message' => 'Pays supprimé.']);
    }

    public function storeState(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:16'],
            'is_active' => ['boolean'],
        ]);

        State::create($data);

        return response()->json(['message' => 'État/Province créé.']);
    }

    public function destroyState(State $state): JsonResponse
    {
        $state->delete();

        return response()->json(['message' => 'État/Province supprimé.']);
    }

    public function storeCity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['required', 'exists:states,id'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        City::create($data);

        return response()->json(['message' => 'Ville créée.']);
    }

    public function destroyCity(City $city): JsonResponse
    {
        $city->delete();

        return response()->json(['message' => 'Ville supprimée.']);
    }
}

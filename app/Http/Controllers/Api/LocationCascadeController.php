<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LocationCascadeController extends Controller
{
    public function countries(Request $request): JsonResponse
    {
        $q = Country::query()->where('is_active', true)->orderBy('name');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'like', $term)->orWhere('code', 'like', $term);
            });
        }

        $rows = $q->get(['id', 'name', 'code', 'iso2', 'phonecode', 'emoji']);

        return response()->json($rows);
    }

    public function states(Request $request, Country $country): JsonResponse
    {
        $q = State::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term);
            });
        }

        return response()->json($q->get(['id', 'name', 'code', 'country_id']));
    }

    public function cities(Request $request, State $state): JsonResponse
    {
        $q = City::query()
            ->where('state_id', $state->id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $q->where('name', 'like', $term);
        }

        return response()->json($q->get(['id', 'name', 'state_id']));
    }

    /**
     * Liste des pays avec indicatif et emoji (pour sélecteur téléphone).
     * Même cache que HandleInertiaRequests::phoneCountries.
     */
    /**
     * Identifiants IANA (ex. Europe/Paris), filtrables par ?search=
     */
    public function timezones(Request $request): JsonResponse
    {
        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

        if ($request->filled('search')) {
            $needle = mb_strtolower((string) $request->input('search'));
            $identifiers = array_values(array_filter(
                $identifiers,
                static fn (string $id): bool => str_contains(mb_strtolower($id), $needle)
            ));
        }

        return response()->json($identifiers);
    }

    public function phoneCountries(): JsonResponse
    {
        $data = Cache::remember('phone_countries', 3600, function () {
            return Country::query()
                ->whereNotNull('phonecode')
                ->where('phonecode', '!=', '')
                ->orderBy('name')
                ->get(['id', 'name', 'iso2', 'phonecode', 'emoji'])
                ->map(fn (Country $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'iso2' => $c->iso2,
                    'phonecode' => $c->phonecode,
                    'emoji' => $c->emoji,
                ])
                ->values()
                ->all();
        });

        return response()->json($data);
    }
}

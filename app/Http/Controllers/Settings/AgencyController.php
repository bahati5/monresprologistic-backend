<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\City;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'agencies' => Agency::query()
                ->withCount('users')
                ->with(['country:id,name,code,iso2,emoji', 'state:id,name', 'city:id,name'])
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:agencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_phone_secondary' => ['nullable', 'string', 'max:64'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        $currency = strtoupper((string) (Setting::getValue('currency', 'USD') ?? 'USD'));
        if (strlen($currency) > 8) {
            $currency = substr($currency, 0, 8);
        }

        $row = $this->resolveLocationRows([
            'country_id' => $data['country_id'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
        ]);
        $row['code'] = strtoupper($data['code']);
        $row['name'] = $data['name'];
        $row['default_currency'] = $currency;
        $row['slug'] = Str::slug($data['name']).'-'.Str::random(4);
        $row['is_active'] = $data['is_active'] ?? true;
        $row['contact_phone'] = $data['contact_phone'] ?? null;
        $row['contact_phone_secondary'] = $data['contact_phone_secondary'] ?? null;
        $row['contact_email'] = $data['contact_email'] ?? null;
        $row['address'] = $data['address'] ?? null;

        Agency::query()->create($row);

        return response()->json(['message' => 'Agence créée.']);
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('agencies', 'code')->ignore($agency->id)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact_phone_secondary' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
        ]);

        $locationInput = [
            'country_id' => array_key_exists('country_id', $data) ? $data['country_id'] : $agency->country_id,
            'state_id' => array_key_exists('state_id', $data) ? $data['state_id'] : $agency->state_id,
            'city_id' => array_key_exists('city_id', $data) ? $data['city_id'] : $agency->city_id,
        ];
        $row = $this->resolveLocationRows($locationInput);
        $row['name'] = $data['name'];
        $row['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $agency->is_active;

        foreach (['contact_phone', 'contact_phone_secondary', 'contact_email', 'address'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $data[$key] === '' || $data[$key] === null ? null : $data[$key];
            }
        }

        if (array_key_exists('code', $data) && is_string($data['code']) && $data['code'] !== '') {
            $row['code'] = strtoupper($data['code']);
        }

        $agency->update($row);

        return response()->json(['message' => 'Agence mise à jour.']);
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null, city_id?: int|null}  $ids
     * @return array{country_id: int|null, state_id: int|null, city_id: int|null}
     */
    private function resolveLocationRows(array $ids): array
    {
        $countryId = $ids['country_id'] ?? null;
        $stateId = $ids['state_id'] ?? null;
        $cityId = $ids['city_id'] ?? null;

        $out = [
            'country_id' => $countryId !== null && $countryId !== '' ? (int) $countryId : null,
            'state_id' => $stateId !== null && $stateId !== '' ? (int) $stateId : null,
            'city_id' => $cityId !== null && $cityId !== '' ? (int) $cityId : null,
        ];

        if (! empty($out['city_id'])) {
            $city = City::query()->find($out['city_id']);
            if ($city) {
                $out['city_id'] = $city->id;
                $out['state_id'] = $city->state_id;
                $out['country_id'] = $city->country_id;
            }
        }

        return $out;
    }
}

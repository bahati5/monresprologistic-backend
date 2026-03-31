<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Country;
use App\Models\ShippingMode;
use App\Models\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingRateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'rates' => ShippingRate::with([
                'agency',
                'originCountry',
                'destCountry',
                'shippingMode',
                'shippingModes',
                'originCountries',
                'destinationCountries',
            ])->get(),
            'agencies' => Agency::where('is_active', true)->get(['id', 'name']),
            'countries' => Country::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'iso2', 'emoji']),
            'shippingModes' => ShippingMode::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $rate = new ShippingRate();
        $this->persistRate($rate, $payload);

        return response()->json(['message' => 'Tarif créé.']);
    }

    public function update(Request $request, ShippingRate $shippingRate): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $this->persistRate($shippingRate, $payload);

        return response()->json(['message' => 'Tarif mis à jour.']);
    }

    public function destroy(ShippingRate $shippingRate): JsonResponse
    {
        $shippingRate->delete();

        return response()->json(['message' => 'Tarif supprimé.']);
    }

    /**
     * @return array{
     *   attrs: array<string, mixed>,
     *   modeIds: int[],
     *   originIds: int[],
     *   destIds: int[]
     * }
     */
    protected function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'origin_country_id' => ['nullable', 'exists:countries,id'],
            'dest_country_id' => ['nullable', 'exists:countries,id'],
            'shipping_mode_id' => ['nullable', 'exists:shipping_modes,id'],
            'shipping_mode_ids' => ['nullable', 'array'],
            'shipping_mode_ids.*' => ['integer', 'exists:shipping_modes,id'],
            'origin_country_ids' => ['nullable', 'array'],
            'origin_country_ids.*' => ['integer', 'exists:countries,id'],
            'dest_country_ids' => ['nullable', 'array'],
            'dest_country_ids.*' => ['integer', 'exists:countries,id'],
            'pricing_type' => ['required', Rule::in(['per_kg', 'per_volume', 'flat'])],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'weight_tiers' => ['nullable', 'json'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($data['weight_tiers'])) {
            $data['weight_tiers'] = json_decode((string) $data['weight_tiers'], true);
        }

        $modeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['shipping_mode_ids'] ?? [])))));
        $originIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['origin_country_ids'] ?? [])))));
        $destIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['dest_country_ids'] ?? [])))));

        unset(
            $data['shipping_mode_ids'],
            $data['origin_country_ids'],
            $data['dest_country_ids'],
        );

        if ($modeIds === [] && ! empty($data['shipping_mode_id'])) {
            $modeIds = [(int) $data['shipping_mode_id']];
        }
        if ($originIds === [] && ! empty($data['origin_country_id'])) {
            $originIds = [(int) $data['origin_country_id']];
        }
        if ($destIds === [] && ! empty($data['dest_country_id'])) {
            $destIds = [(int) $data['dest_country_id']];
        }

        if ($modeIds !== []) {
            $data['shipping_mode_id'] = $modeIds[0];
        } else {
            $data['shipping_mode_id'] = null;
        }
        $data['origin_country_id'] = $originIds !== [] ? $originIds[0] : null;
        $data['dest_country_id'] = $destIds !== [] ? $destIds[0] : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return [
            'attrs' => $data,
            'modeIds' => $modeIds,
            'originIds' => $originIds,
            'destIds' => $destIds,
        ];
    }

    /**
     * @param  array{
     *   attrs: array<string, mixed>,
     *   modeIds: int[],
     *   originIds: int[],
     *   destIds: int[]
     * }  $payload
     */
    protected function persistRate(ShippingRate $rate, array $payload): void
    {
        $rate->fill($payload['attrs'])->save();

        $rate->shippingModes()->sync($payload['modeIds']);
        $rate->originCountries()->sync(
            collect($payload['originIds'])->mapWithKeys(fn (int $id) => [$id => ['scope' => 'origin']])->all()
        );
        $rate->destinationCountries()->sync(
            collect($payload['destIds'])->mapWithKeys(fn (int $id) => [$id => ['scope' => 'destination']])->all()
        );
    }
}

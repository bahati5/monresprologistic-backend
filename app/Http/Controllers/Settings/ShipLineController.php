<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTime;
use App\Models\Setting;
use App\Models\ShipLine;
use App\Models\ShipLineCountry;
use App\Models\ShipLineRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShipLineController extends Controller
{
    public function index(): JsonResponse
    {
        $lines = ShipLine::query()
            ->with([
                'countryScopes.country:id,name,code,iso2',
                'rates' => fn ($q) => $q->orderBy('id')->with(['shippingMode:id,name', 'deliveryTime:id,label,shipping_mode_id']),
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'shipLines' => $lines->map(fn (ShipLine $l) => $this->serializeShipLine($l)),
        ]);
    }

    public function forRoute(Request $request): JsonResponse
    {
        $request->validate([
            'origin_country_id' => ['required', 'integer', 'exists:countries,id'],
            'dest_country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        $originId = (int) $request->input('origin_country_id');
        $destId = (int) $request->input('dest_country_id');

        $lines = ShipLine::query()
            ->where('is_active', true)
            ->with([
                'countryScopes.country:id,name,code,iso2',
                'rates' => fn ($q) => $q->where('is_active', true)->with(['shippingMode:id,name', 'deliveryTime:id,label,shipping_mode_id']),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (ShipLine $l) => $this->lineCoversRoute($l, $originId, $destId))
            ->values();

        return response()->json([
            'ship_lines' => $lines->map(fn (ShipLine $l) => $this->serializeShipLine($l)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $line = DB::transaction(function () use ($data) {
            $line = ShipLine::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncCountries($line, $data['origin_country_ids'], $data['dest_country_ids']);
            $this->syncRates($line, $data['rates']);

            return $line->fresh(['countryScopes.country', 'rates.shippingMode', 'rates.deliveryTime']);
        });

        return response()->json([
            'message' => 'Ligne d\'expédition créée.',
            'ship_line' => $this->serializeShipLine($line),
        ], 201);
    }

    /**
     * Ajoute des pays d’origine / destination et fusionne les tarifs (par mode) sur une ou plusieurs lignes existantes.
     */
    public function mergeRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ship_line_ids' => ['required', 'array', 'min:1'],
            'ship_line_ids.*' => ['integer', 'exists:ship_lines,id'],
            'origin_country_ids' => ['required', 'array', 'min:1'],
            'origin_country_ids.*' => ['integer', 'exists:countries,id'],
            'dest_country_ids' => ['required', 'array', 'min:1'],
            'dest_country_ids.*' => ['integer', 'exists:countries,id'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.shipping_mode_id' => ['required', 'integer', 'exists:shipping_modes,id'],
            'rates.*.delivery_time_id' => ['nullable', 'integer', 'exists:delivery_times,id'],
            'rates.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rates.*.currency' => ['nullable', 'string', 'max:8'],
            'rates.*.pricing_type' => ['required', 'string', Rule::in(['per_kg', 'per_volume', 'flat'])],
            'rates.*.is_active' => ['boolean'],
            'rates.*.volumetric_divisor' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ]);

        $lineIds = array_values(array_unique(array_map('intval', $data['ship_line_ids'])));
        $serialized = [];

        foreach ($lineIds as $lid) {
            $line = ShipLine::query()->findOrFail($lid);
            DB::transaction(function () use ($line, $data): void {
                $this->mergeCountriesIntoLine($line, $data['origin_country_ids'], $data['dest_country_ids']);
                $this->appendOrUpsertRates($line, $data['rates']);
            });
            $line->load(['countryScopes.country', 'rates.shippingMode', 'rates.deliveryTime']);
            $serialized[] = $this->serializeShipLine($line);
        }

        $n = count($serialized);

        return response()->json([
            'message' => $n === 1
                ? 'Route et tarifs ajoutés à la ligne sélectionnée.'
                : "Route et tarifs ajoutés à {$n} lignes.",
            'ship_lines' => $serialized,
        ]);
    }

    public function update(Request $request, ShipLine $shipLine): JsonResponse
    {
        $data = $this->validatedPayload($request);

        DB::transaction(function () use ($shipLine, $data) {
            $shipLine->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncCountries($shipLine, $data['origin_country_ids'], $data['dest_country_ids']);
            $this->syncRates($shipLine, $data['rates']);
        });

        $shipLine->load(['countryScopes.country', 'rates.shippingMode', 'rates.deliveryTime']);

        return response()->json([
            'message' => 'Ligne d\'expédition mise à jour.',
            'ship_line' => $this->serializeShipLine($shipLine),
        ]);
    }

    public function destroy(ShipLine $shipLine): JsonResponse
    {
        $shipLine->delete();

        return response()->json(['message' => 'Ligne d\'expédition supprimée.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'origin_country_ids' => ['required', 'array', 'min:1'],
            'origin_country_ids.*' => ['integer', 'exists:countries,id'],
            'dest_country_ids' => ['required', 'array', 'min:1'],
            'dest_country_ids.*' => ['integer', 'exists:countries,id'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.shipping_mode_id' => ['required', 'integer', 'exists:shipping_modes,id'],
            'rates.*.delivery_time_id' => ['nullable', 'integer', 'exists:delivery_times,id'],
            'rates.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rates.*.currency' => ['nullable', 'string', 'max:8'],
            'rates.*.pricing_type' => ['required', 'string', Rule::in(['per_kg', 'per_volume', 'flat'])],
            'rates.*.is_active' => ['boolean'],
            'rates.*.volumetric_divisor' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    protected function syncRates(ShipLine $line, array $rates): void
    {
        $line->rates()->delete();
        $defaultCurrency = strtoupper((string) Setting::getValue('currency', 'USD'));

        foreach ($rates as $row) {
            $modeId = (int) $row['shipping_mode_id'];
            $dtId = isset($row['delivery_time_id']) && $row['delivery_time_id'] !== '' && $row['delivery_time_id'] !== null
                ? (int) $row['delivery_time_id'] : null;
            if ($dtId !== null && $dtId > 0) {
                $ok = DeliveryTime::query()->whereKey($dtId)->where('shipping_mode_id', $modeId)->exists();
                if (! $ok) {
                    throw ValidationException::withMessages([
                        'rates' => ['Le délai ne correspond pas au mode d\'expédition.'],
                    ]);
                }
            } else {
                $dtId = null;
            }

            ShipLineRate::query()->create([
                'ship_line_id' => $line->id,
                'shipping_mode_id' => $modeId,
                'delivery_time_id' => $dtId,
                'unit_price' => (float) $row['unit_price'],
                'currency' => $defaultCurrency,
                'pricing_type' => (string) $row['pricing_type'],
                'is_active' => (bool) ($row['is_active'] ?? true),
                'volumetric_divisor' => isset($row['volumetric_divisor']) && $row['volumetric_divisor'] !== '' && $row['volumetric_divisor'] !== null
                    ? (int) $row['volumetric_divisor'] : null,
            ]);
        }
    }

    /**
     * @param  array<int>  $originIds
     * @param  array<int>  $destIds
     */
    protected function syncCountries(ShipLine $line, array $originIds, array $destIds): void
    {
        $line->countryScopes()->delete();
        $now = now();
        $rows = [];
        foreach (array_unique(array_map('intval', $originIds)) as $cid) {
            if ($cid > 0) {
                $rows[] = ['ship_line_id' => $line->id, 'country_id' => $cid, 'scope' => 'origin', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_unique(array_map('intval', $destIds)) as $cid) {
            if ($cid > 0) {
                $rows[] = ['ship_line_id' => $line->id, 'country_id' => $cid, 'scope' => 'destination', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            DB::table('ship_line_countries')->insert($rows);
        }
    }

    /**
     * @param  array<int>  $originIds
     * @param  array<int>  $destIds
     */
    protected function mergeCountriesIntoLine(ShipLine $line, array $originIds, array $destIds): void
    {
        foreach (array_unique(array_map('intval', $originIds)) as $cid) {
            if ($cid <= 0) {
                continue;
            }
            ShipLineCountry::query()->firstOrCreate(
                [
                    'ship_line_id' => $line->id,
                    'country_id' => $cid,
                    'scope' => 'origin',
                ],
                [],
            );
        }
        foreach (array_unique(array_map('intval', $destIds)) as $cid) {
            if ($cid <= 0) {
                continue;
            }
            ShipLineCountry::query()->firstOrCreate(
                [
                    'ship_line_id' => $line->id,
                    'country_id' => $cid,
                    'scope' => 'destination',
                ],
                [],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    protected function appendOrUpsertRates(ShipLine $line, array $rates): void
    {
        $defaultCurrency = strtoupper((string) Setting::getValue('currency', 'USD'));

        foreach ($rates as $row) {
            $modeId = (int) $row['shipping_mode_id'];
            $dtId = isset($row['delivery_time_id']) && $row['delivery_time_id'] !== '' && $row['delivery_time_id'] !== null
                ? (int) $row['delivery_time_id'] : null;
            if ($dtId !== null && $dtId > 0) {
                $ok = DeliveryTime::query()->whereKey($dtId)->where('shipping_mode_id', $modeId)->exists();
                if (! $ok) {
                    throw ValidationException::withMessages([
                        'rates' => ['Le délai ne correspond pas au mode d\'expédition.'],
                    ]);
                }
            } else {
                $dtId = null;
            }

            $vol = isset($row['volumetric_divisor']) && $row['volumetric_divisor'] !== '' && $row['volumetric_divisor'] !== null
                ? (int) $row['volumetric_divisor'] : null;

            ShipLineRate::query()->updateOrCreate(
                [
                    'ship_line_id' => $line->id,
                    'shipping_mode_id' => $modeId,
                ],
                [
                    'delivery_time_id' => $dtId,
                    'unit_price' => (float) $row['unit_price'],
                    'currency' => $defaultCurrency,
                    'pricing_type' => (string) $row['pricing_type'],
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'volumetric_divisor' => $vol !== null && $vol >= 1 ? $vol : null,
                ],
            );
        }
    }

    protected function lineCoversRoute(ShipLine $line, int $originCountryId, int $destCountryId): bool
    {
        $scopes = $line->relationLoaded('countryScopes') ? $line->countryScopes : $line->countryScopes()->get();
        $origins = $scopes->where('scope', 'origin')->pluck('country_id')->map(fn ($id) => (int) $id)->all();
        $dests = $scopes->where('scope', 'destination')->pluck('country_id')->map(fn ($id) => (int) $id)->all();

        if ($origins === [] || $dests === []) {
            return false;
        }

        return in_array($originCountryId, $origins, true) && in_array($destCountryId, $dests, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeShipLine(ShipLine $line): array
    {
        $scopes = $line->relationLoaded('countryScopes') ? $line->countryScopes : $line->countryScopes()->with('country')->get();
        $origins = $scopes->where('scope', 'origin')->map(fn ($r) => $r->country)->filter()->values();
        $dests = $scopes->where('scope', 'destination')->map(fn ($r) => $r->country)->filter()->values();

        $rates = $line->relationLoaded('rates') ? $line->rates : $line->rates()->with(['shippingMode', 'deliveryTime'])->get();

        return [
            'id' => $line->id,
            'name' => $line->name,
            'description' => $line->description,
            'is_active' => (bool) $line->is_active,
            'origin_countries' => $origins->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'iso2' => $c->iso2])->all(),
            'destination_countries' => $dests->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'iso2' => $c->iso2])->all(),
            'rates' => $rates->map(fn (ShipLineRate $r) => [
                'id' => $r->id,
                'ship_line_id' => $r->ship_line_id,
                'shipping_mode_id' => $r->shipping_mode_id,
                'delivery_time_id' => $r->delivery_time_id,
                'unit_price' => (float) $r->unit_price,
                'currency' => $r->currency,
                'pricing_type' => $r->pricing_type,
                'is_active' => (bool) $r->is_active,
                'volumetric_divisor' => $r->volumetric_divisor,
                'shipping_mode' => $r->shippingMode ? ['id' => $r->shippingMode->id, 'name' => $r->shippingMode->name] : null,
                'delivery_time' => $r->deliveryTime ? ['id' => $r->deliveryTime->id, 'label' => $r->deliveryTime->label] : null,
            ])->values()->all(),
        ];
    }
}

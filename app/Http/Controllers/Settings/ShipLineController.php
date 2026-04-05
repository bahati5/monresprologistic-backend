<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Setting;
use App\Models\ShipLine;
use App\Models\ShipLineCountry;
use App\Models\ShipLineRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipLineController extends Controller
{
    public function index(): JsonResponse
    {
        $lines = ShipLine::query()
            ->with([
                'countryScopes.country:id,name,code,iso2,emoji',
                'rates' => fn ($q) => $q->orderBy('id')->with(['shippingMode:id,name,default_pricing_type']),
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
                'countryScopes.country:id,name,code,iso2,emoji',
                'rates' => fn ($q) => $q->where('is_active', true)->with(['shippingMode:id,name,default_pricing_type,delivery_options']),
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
        $data['name'] = $this->resolveShipLineName(
            $data['origin_country_ids'],
            $data['dest_country_ids'],
            trim((string) ($data['name'] ?? '')),
        );

        $line = DB::transaction(function () use ($data) {
            $line = ShipLine::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncCountries($line, $data['origin_country_ids'], $data['dest_country_ids']);
            $this->syncRates($line, $data['rates']);

            return $line->fresh(['countryScopes.country', 'rates.shippingMode']);
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
            'rates.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rates.*.currency' => ['nullable', 'string', 'max:8'],
            'rates.*.is_active' => ['boolean'],
            'rates.*.delivery_label_override' => ['nullable', 'string', 'max:500'],
        ]);

        $lineIds = array_values(array_unique(array_map('intval', $data['ship_line_ids'])));
        $serialized = [];

        foreach ($lineIds as $lid) {
            $line = ShipLine::query()->findOrFail($lid);
            DB::transaction(function () use ($line, $data): void {
                $this->mergeCountriesIntoLine($line, $data['origin_country_ids'], $data['dest_country_ids']);
                $this->appendOrUpsertRates($line, $data['rates']);
            });
            $line->load(['countryScopes.country', 'rates.shippingMode']);
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

        $preferred = trim((string) ($data['name'] ?? ''));
        if ($preferred === '') {
            $preferred = (string) ($shipLine->name ?? '');
        }
        $data['name'] = $this->resolveShipLineName(
            $data['origin_country_ids'],
            $data['dest_country_ids'],
            $preferred,
        );

        DB::transaction(function () use ($shipLine, $data) {
            $shipLine->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncCountries($shipLine, $data['origin_country_ids'], $data['dest_country_ids']);
            $this->syncRates($shipLine, $data['rates']);
        });

        $shipLine->load(['countryScopes.country', 'rates.shippingMode']);

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
     * @param  array<int>  $originIds
     * @param  array<int>  $destIds
     */
    protected function resolveShipLineName(array $originIds, array $destIds, string $preferred = ''): string
    {
        if ($preferred !== '') {
            return mb_substr($preferred, 0, 255);
        }

        $format = function (array $ids): string {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
            if ($ids === []) {
                return '';
            }

            return Country::query()
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->get(['name', 'iso2', 'code'])
                ->map(function (Country $c) {
                    $t = $c->iso2 ?: $c->code;
                    if (is_string($t) && trim($t) !== '') {
                        return mb_strtoupper(trim($t));
                    }

                    return mb_substr((string) $c->name, 0, 12);
                })
                ->filter()
                ->take(6)
                ->implode(', ');
        };

        $left = $format($originIds);
        $right = $format($destIds);
        $base = trim($left.' → '.$right);

        return $base !== '' ? mb_substr($base, 0, 255) : 'Ligne';
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'origin_country_ids' => ['required', 'array', 'min:1'],
            'origin_country_ids.*' => ['integer', 'exists:countries,id'],
            'dest_country_ids' => ['required', 'array', 'min:1'],
            'dest_country_ids.*' => ['integer', 'exists:countries,id'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.shipping_mode_id' => ['required', 'integer', 'exists:shipping_modes,id'],
            'rates.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rates.*.currency' => ['nullable', 'string', 'max:8'],
            'rates.*.is_active' => ['boolean'],
            'rates.*.delivery_label_override' => ['nullable', 'string', 'max:500'],
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
            $ov = isset($row['delivery_label_override']) ? trim((string) $row['delivery_label_override']) : '';

            ShipLineRate::query()->create([
                'ship_line_id' => $line->id,
                'shipping_mode_id' => $modeId,
                'delivery_label_override' => $ov !== '' ? $ov : null,
                'unit_price' => (float) $row['unit_price'],
                'currency' => $defaultCurrency,
                'is_active' => (bool) ($row['is_active'] ?? true),
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
            $ov = isset($row['delivery_label_override']) ? trim((string) $row['delivery_label_override']) : '';

            ShipLineRate::query()->updateOrCreate(
                [
                    'ship_line_id' => $line->id,
                    'shipping_mode_id' => $modeId,
                ],
                [
                    'delivery_label_override' => $ov !== '' ? $ov : null,
                    'unit_price' => (float) $row['unit_price'],
                    'currency' => $defaultCurrency,
                    'is_active' => (bool) ($row['is_active'] ?? true),
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

        $rates = $line->relationLoaded('rates') ? $line->rates : $line->rates()->with(['shippingMode'])->get();

        return [
            'id' => $line->id,
            'name' => $line->name,
            'description' => $line->description,
            'is_active' => (bool) $line->is_active,
            'origin_countries' => $origins->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'iso2' => $c->iso2,
                'emoji' => $c->emoji,
            ])->all(),
            'destination_countries' => $dests->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'iso2' => $c->iso2,
                'emoji' => $c->emoji,
            ])->all(),
            'rates' => $rates->map(fn (ShipLineRate $r) => [
                'id' => $r->id,
                'ship_line_id' => $r->ship_line_id,
                'shipping_mode_id' => $r->shipping_mode_id,
                'delivery_label_override' => $r->delivery_label_override,
                'unit_price' => (float) $r->unit_price,
                'currency' => $r->currency,
                'is_active' => (bool) $r->is_active,
                'shipping_mode' => $r->shippingMode ? [
                    'id' => $r->shippingMode->id,
                    'name' => $r->shippingMode->name,
                    'delivery_options' => $r->shippingMode->delivery_options,
                ] : null,
            ])->values()->all(),
        ];
    }
}

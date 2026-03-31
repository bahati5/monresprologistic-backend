<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTime;
use App\Models\ShippingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingModeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'shippingModes' => ShippingMode::query()
                ->with(['deliveryTimes' => fn ($q) => $q->orderBy('sort_order')])
                ->withCount('deliveryTimes')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedModeRequest($request);
        $deliveryRows = $data['delivery_times'] ?? [];
        unset($data['delivery_times']);

        $mode = ShippingMode::create($data);
        $this->syncDeliveryTimes($mode, $deliveryRows);

        return response()->json(['message' => 'Mode d\'expédition créé.']);
    }

    public function update(Request $request, ShippingMode $shippingMode): JsonResponse
    {
        $data = $this->validatedModeRequest($request);
        $deliveryRows = $data['delivery_times'] ?? [];
        unset($data['delivery_times']);

        $shippingMode->update($data);
        $this->syncDeliveryTimes($shippingMode, $deliveryRows);

        return response()->json(['message' => 'Mode d\'expédition mis à jour.']);
    }

    public function destroy(ShippingMode $shippingMode): JsonResponse
    {
        $shippingMode->delete();

        return response()->json(['message' => 'Mode d\'expédition supprimé.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedModeRequest(Request $request): array
    {
        $input = $request->all();
        if (isset($input['delivery_times']) && is_array($input['delivery_times'])) {
            $input['delivery_times'] = array_values(array_filter(
                $input['delivery_times'],
                fn ($r) => trim((string) ($r['label'] ?? '')) !== ''
            ));
            $request->merge($input);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'delivery_times' => ['nullable', 'array'],
            'delivery_times.*.id' => ['nullable', 'integer', 'exists:delivery_times,id'],
            'delivery_times.*.label' => ['required', 'string', 'max:255'],
            'delivery_times.*.description' => ['nullable', 'string', 'max:1000'],
            'delivery_times.*.is_active' => ['boolean'],
            'delivery_times.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     */
    protected function syncDeliveryTimes(ShippingMode $mode, ?array $rows): void
    {
        if ($rows === null) {
            return;
        }

        $ids = [];
        foreach ($rows as $i => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $payload = [
                'label' => $label,
                'description' => isset($row['description']) && $row['description'] !== ''
                    ? (string) $row['description']
                    : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) ($row['sort_order'] ?? $i),
                'shipping_mode_id' => $mode->id,
            ];

            if (! empty($row['id'])) {
                $id = (int) $row['id'];
                $dt = DeliveryTime::query()
                    ->where('id', $id)
                    ->where('shipping_mode_id', $mode->id)
                    ->first();
                if ($dt) {
                    $dt->update($payload);
                    $ids[] = $dt->id;
                }
            } else {
                $dt = DeliveryTime::create($payload);
                $ids[] = $dt->id;
            }
        }

        DeliveryTime::query()
            ->where('shipping_mode_id', $mode->id)
            ->whereNotIn('id', $ids)
            ->delete();
    }
}

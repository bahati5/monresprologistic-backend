<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ShippingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingModeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'shippingModes' => ShippingMode::query()
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedModeRequest($request);
        ShippingMode::create($data);

        return response()->json(['message' => 'Mode d\'expédition créé.']);
    }

    public function update(Request $request, ShippingMode $shippingMode): JsonResponse
    {
        $data = $this->validatedModeRequest($request);
        $shippingMode->update($data);

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
        if (isset($input['delivery_options']) && is_array($input['delivery_options'])) {
            $input['delivery_options'] = array_values(array_filter(
                array_map(fn ($s) => trim((string) $s), $input['delivery_options']),
                fn ($s) => $s !== ''
            ));
            $request->merge($input);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'volumetric_divisor' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'default_pricing_type' => ['nullable', 'string', Rule::in(['per_kg', 'per_volume', 'flat'])],
            'delivery_options' => ['nullable', 'array'],
            'delivery_options.*' => ['required', 'string', 'max:255'],
        ]);
    }
}

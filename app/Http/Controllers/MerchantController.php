<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantController extends Controller
{
    /**
     * Marchands actifs : formulaire client (shopping assisté).
     */
    public function active(): JsonResponse
    {
        $merchants = Merchant::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'domains', 'logo_url']);

        return response()->json(['merchants' => $merchants]);
    }

    public function index(): JsonResponse
    {
        $merchants = Merchant::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['merchants' => $merchants]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = Merchant::query()->create($this->validatedMerchant($request));

        return response()->json([
            'message' => 'Marchand créé.',
            'merchant' => $merchant,
        ]);
    }

    public function update(Request $request, Merchant $merchant): JsonResponse
    {
        $merchant->update($this->validatedMerchant($request, true));

        return response()->json([
            'message' => 'Marchand mis à jour.',
            'merchant' => $merchant->fresh(),
        ]);
    }

    public function destroy(Merchant $merchant): JsonResponse
    {
        $merchant->delete();

        return response()->json(['message' => 'Marchand supprimé.']);
    }

    /**
     * @return array{name?: string, domains?: array<int, string>, logo_url?: string|null, is_active?: bool, sort_order?: int}
     */
    protected function validatedMerchant(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        /** @var array{name?: string, domains?: array<int, string>, logo_url?: string|null, is_active?: bool, sort_order?: int} $data */
        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'domains' => [$required, 'array', 'min:1'],
            'domains.*' => ['string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (array_key_exists('domains', $data)) {
            $data['domains'] = array_values(array_filter(
                array_map(
                    static fn (string $d): string => strtolower(trim($d)),
                    $data['domains']
                ),
                static fn (string $d): bool => $d !== ''
            ));

            if ($data['domains'] === []) {
                throw ValidationException::withMessages([
                    'domains' => ['Ajoutez au moins un domaine ou alias valide.'],
                ]);
            }
        }

        return $data;
    }
}

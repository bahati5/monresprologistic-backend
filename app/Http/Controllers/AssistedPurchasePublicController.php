<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Jobs\ScrapeAndPersistProductJob;
use App\Jobs\ScrapeProductJob;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Services\ProspectResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistedPurchasePublicController extends Controller
{
    public function store(Request $request, ProspectResolverService $prospectResolver): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'links' => ['required', 'array', 'min:1', 'max:10'],
            'links.*.url' => ['required', 'url', 'max:2048'],
            'links.*.name' => ['nullable', 'string', 'max:255'],
            'links.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $resolved = $prospectResolver->resolve(
            $data['email'],
            $data['full_name'],
            $data['phone'] ?? null,
        );

        $result = DB::transaction(function () use ($data, $resolved) {
            $purchase = AssistedPurchase::create([
                'user_id' => $resolved['user_id'],
                'status' => AssistedPurchaseStatus::PENDING_QUOTE,
                'product_url' => $data['links'][0]['url'],
                'article_label' => $data['links'][0]['name'] ?? $this->extractDomain($data['links'][0]['url']),
                'quantity' => $data['links'][0]['quantity'] ?? 1,
                'notes' => $data['note'] ?? null,
                'line_notes' => json_encode([
                    'source' => 'public_form',
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'is_prospect' => $resolved['is_prospect'],
                ]),
            ]);

            foreach ($data['links'] as $link) {
                $item = AssistedPurchaseItem::create([
                    'assisted_purchase_id' => $purchase->id,
                    'url' => $link['url'],
                    'name' => $link['name'] ?? $this->extractDomain($link['url']),
                    'quantity' => $link['quantity'] ?? 1,
                    'unit_price' => 0,
                ]);

                $cacheKey = 'scrape_public_' . $item->id . '_' . md5($link['url']);
                ScrapeProductJob::dispatch($link['url'], $cacheKey);
                ScrapeAndPersistProductJob::dispatch($item->id);
            }

            return $purchase;
        });

        return response()->json([
            'message' => $resolved['is_prospect']
                ? 'Demande enregistrée. Vous recevrez un email de confirmation.'
                : 'Demande enregistrée avec succès.',
            'reference' => 'AP-' . str_pad($result->id, 6, '0', STR_PAD_LEFT),
        ], 201);
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return str_replace('www.', '', $host);
    }
}

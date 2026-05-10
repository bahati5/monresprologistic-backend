<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index(): JsonResponse
    {
        $currentRates = ExchangeRate::query()
            ->selectRaw('from_currency, to_currency, MAX(id) as id')
            ->where('valid_from', '<=', now())
            ->groupBy('from_currency', 'to_currency')
            ->get()
            ->map(fn ($r) => ExchangeRate::find($r->id))
            ->filter();

        $history = ExchangeRate::query()
            ->with('setByUser:id,name')
            ->orderByDesc('valid_from')
            ->limit(100)
            ->get();

        return response()->json([
            'current_rates' => $currentRates->values(),
            'history' => $history,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency' => ['required', 'string', 'max:8'],
            'to_currency' => ['nullable', 'string', 'max:8'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $rate = ExchangeRate::create([
            'from_currency' => strtoupper($data['from_currency']),
            'to_currency' => strtoupper($data['to_currency'] ?? 'USD'),
            'rate' => $data['rate'],
            'set_by' => $request->user()->id,
            'valid_from' => now(),
        ]);

        return response()->json(['rate' => $rate->load('setByUser')], 201);
    }
}

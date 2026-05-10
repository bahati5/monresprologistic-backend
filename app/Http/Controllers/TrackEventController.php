<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Télémétrie anonyme du suivi public (sans PII : seulement un hash court du code).
 */
class TrackEventController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:search_ok,search_fail'],
            'h' => ['nullable', 'string', 'max:64'],
        ]);

        Log::info('public_track_event', [
            'event' => $data['event'],
            'h' => $data['h'] ?? null,
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 200),
        ]);

        return response()->noContent();
    }
}

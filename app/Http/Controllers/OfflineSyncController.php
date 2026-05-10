<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Reprise de file d’attente hors-ligne (PRD ERR-01).
 * Les entrées sont des marqueurs en cache (`offline_queue:{id}`) posés par le client ou une couche future.
 */
class OfflineSyncController extends Controller
{
    public function processQueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'queue_ids' => ['required', 'array'],
            'queue_ids.*' => ['string', 'max:128'],
        ]);

        $processed = 0;
        $failed = 0;

        foreach ($data['queue_ids'] as $queueId) {
            $key = 'offline_queue:'.$queueId;
            if (Cache::pull($key) !== null) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return response()->json([
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }
}

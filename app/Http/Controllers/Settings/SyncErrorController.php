<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SyncError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncErrorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SyncError::query()->latest();

        if ($request->has('resolved')) {
            $query->where('resolved', filter_var($request->input('resolved'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('integration')) {
            $query->forIntegration($request->input('integration'));
        }

        return response()->json([
            'sync_errors' => $query->paginate(25),
        ]);
    }

    public function retry(SyncError $syncError): JsonResponse
    {
        if ($syncError->resolved) {
            return response()->json(['message' => 'Cette erreur est déjà résolue.'], 422);
        }

        dispatch(new \App\Jobs\RetrySyncErrorJob($syncError));

        return response()->json(['message' => 'Retry planifié.']);
    }

    public function resolve(SyncError $syncError): JsonResponse
    {
        $syncError->markResolved();

        return response()->json(['message' => 'Erreur marquée comme résolue.']);
    }
}

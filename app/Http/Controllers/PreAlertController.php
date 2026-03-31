<?php

namespace App\Http\Controllers;

use App\Models\PreAlert;
use App\Models\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = PreAlert::query()->with(['user', 'locker', 'status'])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        return response()->json([
            'preAlerts' => $q->paginate(15),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        return response()->json([
            'locker' => $request->user()->locker,
        ]);
    }

    public function show(PreAlert $pre_alert): JsonResponse
    {
        $pre_alert->load(['user', 'locker', 'status', 'media', 'customerPackage.status']);

        return response()->json([
            'preAlert' => $pre_alert,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'vendor_tracking_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $status = Status::query()->where('code', 'created')->first();

        $preAlert = PreAlert::query()->create([
            ...$data,
            'user_id' => $user->id,
            'locker_id' => $user->locker?->id,
            'status_id' => $status?->id,
        ]);

        foreach ($request->allFiles() as $key => $uploads) {
            $uploads = is_array($uploads) ? $uploads : [$uploads];
            foreach ($uploads as $file) {
                if ($file && $file->isValid()) {
                    $preAlert->addMedia($file)->toMediaCollection('documents');
                }
            }
        }

        return response()->json(['message' => 'Pré-alerte enregistrée.']);
    }
}

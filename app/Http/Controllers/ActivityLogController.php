<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer')
            ->latest();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHas('causer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($event = $request->get('event')) {
            $query->where('event', $event);
        }

        $perPage = min((int) $request->get('per_page', 25), 100);

        $paginated = $query->paginate($perPage);

        $data = collect($paginated->items())->map(function (Activity $activity) {
            return [
                'id' => $activity->id,
                'uuid' => $activity->uuid ?? null,
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'causer_name' => $activity->causer?->name,
                'causer_email' => $activity->causer?->email,
                'properties' => $activity->properties?->toArray() ?? [],
                'event' => $activity->event,
                'created_at' => $activity->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
        ]);
    }
}

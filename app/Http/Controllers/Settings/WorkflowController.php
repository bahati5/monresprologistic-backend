<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\StatusTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'statuses' => Status::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'sort_order']),
            'transitions' => StatusTransition::query()
                ->with(['fromStatus:id,code,name', 'toStatus:id,code,name'])
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_status_id' => ['required', 'exists:statuses,id'],
            'to_status_id' => ['required', 'exists:statuses,id', 'different:from_status_id'],
        ]);

        StatusTransition::query()->firstOrCreate($data);

        return response()->json(['message' => 'Transition ajoutée.']);
    }

    public function destroy(StatusTransition $status_transition): JsonResponse
    {
        $status_transition->delete();

        return response()->json(['message' => 'Transition supprimée.']);
    }
}

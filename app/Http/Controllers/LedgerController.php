<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_finances'), 403);

        $user = $request->user();
        $q = LedgerEntry::query()->with(['agency', 'user', 'invoice'])->latest();

        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        return response()->json([
            'entries' => $q->paginate(40),
        ]);
    }
}

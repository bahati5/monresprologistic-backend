<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LockerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->locker, 404);

        return response()->json([
            'locker' => $user->locker->load('preAlerts'),
        ]);
    }
}

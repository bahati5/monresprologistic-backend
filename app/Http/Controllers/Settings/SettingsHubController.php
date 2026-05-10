<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingsHubController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['message' => 'OK']);
    }
}

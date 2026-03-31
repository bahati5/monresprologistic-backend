<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'theme_preference' => ['required', 'in:light,dark,system'],
        ]);

        $request->user()->update([
            'theme_preference' => $request->string('theme_preference'),
        ]);

        return response()->json(['message' => 'Préférence enregistrée.']);
    }
}

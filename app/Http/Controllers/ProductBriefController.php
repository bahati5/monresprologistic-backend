<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * PRD V3 — chapitres 1 à 3 : contexte, problèmes terrain, principes directeurs.
 */
class ProductBriefController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(config('product_brief'));
    }
}

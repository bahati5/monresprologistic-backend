<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => ArticleCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        ArticleCategory::create($data);

        return response()->json(['message' => 'Catégorie créée.']);
    }

    public function update(Request $request, ArticleCategory $articleCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $articleCategory->update($data);

        return response()->json(['message' => 'Catégorie mise à jour.']);
    }

    public function destroy(ArticleCategory $articleCategory): JsonResponse
    {
        $articleCategory->delete();

        return response()->json(['message' => 'Catégorie supprimée.']);
    }
}

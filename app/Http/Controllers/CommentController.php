<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'string', 'in:assisted_purchase,shipment,refund'],
            'commentable_id' => ['required', 'integer'],
        ]);

        $morphMap = [
            'assisted_purchase' => \App\Models\AssistedPurchase::class,
            'shipment' => \App\Models\Shipment::class,
            'refund' => \App\Models\Refund::class,
        ];

        $user = $request->user();
        $isClient = $user->hasRole('client');

        $query = Comment::where('commentable_type', $morphMap[$data['commentable_type']])
            ->where('commentable_id', $data['commentable_id'])
            ->with('user:id,name')
            ->orderBy('created_at');

        if ($isClient) {
            $query->where('is_internal', false);
        }

        return response()->json([
            'comments' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'string', 'in:assisted_purchase,shipment,refund'],
            'commentable_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $morphMap = [
            'assisted_purchase' => \App\Models\AssistedPurchase::class,
            'shipment' => \App\Models\Shipment::class,
            'refund' => \App\Models\Refund::class,
        ];

        $user = $request->user();
        $isClient = $user->hasRole('client');

        $comment = Comment::create([
            'commentable_type' => $morphMap[$data['commentable_type']],
            'commentable_id' => $data['commentable_id'],
            'user_id' => $user->id,
            'body' => $data['body'],
            'is_internal' => $isClient ? false : ($data['is_internal'] ?? false),
        ]);

        return response()->json([
            'comment' => $comment->load('user:id,name'),
        ], 201);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json(['success' => true]);
    }
}

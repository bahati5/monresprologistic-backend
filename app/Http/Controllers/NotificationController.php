<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $notifications = Notification::forUser($user->id)
            ->inApp()
            ->orderByDesc('created_at')
            ->paginate(20);

        $unreadCount = Notification::forUser($user->id)
            ->inApp()
            ->unread()
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::forUser($request->user()->id)
            ->inApp()
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);
        
        $notification->markAsRead();
        
        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::forUser($request->user()->id)
            ->inApp()
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $this->authorize('delete', $notification);
        
        $notification->delete();
        
        return response()->json(['success' => true]);
    }

    /**
     * §18.5 PRD — Journal d'audit de toutes les notifications envoyées.
     */
    public function auditLog(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage_notifications'), 403);

        $q = Notification::query()->with('user')->latest();

        if ($request->filled('channel')) {
            $q->where('type', $request->input('channel'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return response()->json(['notifications' => $q->paginate(30)]);
    }

    /**
     * Send notification via different channels
     */
    public static function notify(
        int $userId,
        string $channel,
        string $title,
        string $body,
        array $data = [],
        ?string $actionUrl = null,
        array $channels = ['in_app']
    ): void {
        foreach ($channels as $type) {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'channel' => $channel,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'action_url' => $actionUrl,
                'status' => $type === 'in_app' ? 'sent' : 'pending',
                'sent_at' => $type === 'in_app' ? now() : null,
            ]);
        }
    }
}

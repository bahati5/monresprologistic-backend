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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);

            // Get user's notifications, ordered by latest
            $notifications = Auth::user()
                ->notifications()
                ->paginate($perPage);

            return response()->json($notifications);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch notifications', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get unread count.
     */
    public function unreadCount()
    {
        return response()->json([
            'data' => [
                'count' => Auth::user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead($id)
    {
        try {
            $notification = Auth::user()->notifications()->findOrFail($id);
            $notification->markAsRead();

            return response()->json(['message' => 'Marked as read']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to mark as read'], 500);
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        try {
            Auth::user()->unreadNotifications->markAsRead();

            return response()->json(['message' => 'All marked as read']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to mark all as read'], 500);
        }
    }

    /**
     * Remove the specified notification.
     */
    public function destroy($id)
    {
        try {
            $notification = Auth::user()->notifications()->findOrFail($id);
            $notification->delete();

            return response()->json(['message' => 'Notification deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete notification'], 500);
        }
    }

    /**
     * Clear all notifications.
     */
    public function destroyAll()
    {
        try {
            Auth::user()->notifications()->delete();

            return response()->json(['message' => 'All notifications deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete all notifications'], 500);
        }
    }
}

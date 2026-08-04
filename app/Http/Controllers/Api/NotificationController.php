<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
    /**
     * Get list of notifications for the active tenant.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Notification::orderBy('created_at', 'desc');

        if ($user->isAgent()) {
            $query->whereIn('conversation_id', function ($subQuery) use ($user) {
                $subQuery->select('id')
                    ->from('conversations')
                    ->where(function ($q) use ($user) {
                        $q->where('assigned_to', $user->id)
                          ->orWhereNull('assigned_to');
                    });
            });
        }

        $notifications = $query->get();

        return response()->json($notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'notification' => $notification
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        Notification::where('is_read', false)->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.'
        ]);
    }

    /**
     * Delete all notifications for the active tenant.
     */
    public function clearAll(Request $request)
    {
        Notification::query()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications cleared.'
        ]);
    }
}

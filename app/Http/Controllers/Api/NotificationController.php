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
            $tenant = $user->tenant;
            $isDelivery = $tenant && $tenant->type === 'delivery_coordination';

            $query->whereIn('conversation_id', function ($subQuery) use ($user, $isDelivery) {
                $subQuery->select('id')
                    ->from('conversations')
                    ->where(function ($q) use ($user, $isDelivery) {
                        $q->where('assigned_to', $user->id)
                          ->orWhereNull('assigned_to');

                        if ($isDelivery) {
                            $driverPhones = \App\Models\Driver::pluck('phone_number')->toArray();
                            if (!empty($driverPhones)) {
                                $q->orWhereIn('contact_id', function ($cq) use ($driverPhones) {
                                    $cq->select('id')
                                        ->from('contacts')
                                        ->whereIn('phone_number', $driverPhones);
                                });
                            }
                        }
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

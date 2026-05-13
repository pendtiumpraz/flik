<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = auth()->user()->notifications()->paginate(20);
        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Notification $notification)
    {
        // NotificationPolicy::markAsRead() — strict ownership, admin override.
        $this->authorize('markAsRead', $notification);

        $notification->markAsRead();

        if ($notification->action_url) {
            return redirect($notification->action_url);
        }
        return back();
    }

    public function markAllAsRead()
    {
        auth()->user()->notifications()->unread()->update(['read_at' => now()]);
        return back()->with('success', 'Semua notifikasi sudah dibaca!');
    }

    public function count()
    {
        return response()->json([
            'count' => auth()->user()->unreadNotificationCount(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->notifications()
            ->whereNull('dismissed_at')
            ->with('schedule')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Request $request, Notification $notification): RedirectResponse
    {
        $ownedNotification = $request->user()->notifications()->findOrFail($notification->id);
        $ownedNotification->update(['is_read' => true]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function dismiss(Request $request, Notification $notification): RedirectResponse
    {
        $ownedNotification = $request->user()->notifications()->findOrFail($notification->id);
        abort_unless($ownedNotification->isCommitmentRiskAlert(), 404);

        $ownedNotification->update(['dismissed_at' => now(), 'is_read' => true]);

        return back()->with('status', 'Commitment alert dismissed.');
    }
}

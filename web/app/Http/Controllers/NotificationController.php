<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $unread = $user->unreadNotifications()->pluck('id')->all();
        $items = $user->notifications()->latest()->paginate(15);
        $user->unreadNotifications()->update(['read_at' => now()]);

        return view('notifications.index', compact('items', 'unread'));
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(30),
        ]);
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        $url = $item->data['url'] ?? null;
        return $url ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return back()->with('success', 'Notificações marcadas como lidas.');
    }
}

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
        $newDepartmentAlerts = $request->user()->unreadNotifications()
            ->orderByDesc('created_at')->limit(40)->get()
            ->filter(fn ($item) => str_starts_with((string) ($item->data['event'] ?? ''), 'ticket.department.')
                && !empty($item->data['department_id']))
            ->take(5)->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->data['title'] ?? 'Novidade no departamento',
                'message' => $item->data['message'] ?? '',
                'url' => $item->data['url'] ?? route('notifications.index'),
            ])->values();

        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
            'department_alerts' => $newDepartmentAlerts,
        ])->header('Cache-Control', 'no-store, private');
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

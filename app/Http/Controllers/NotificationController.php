<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    private const PAGE_SIZES = [10, 20, 30, 40, 50];

    public function index(Request $request)
    {
        $user = $request->user();

        // A página nunca volta a exibir notificações que já ultrapassaram a
        // retenção. O comando agendado faz a limpeza global diariamente.
        $user->notifications()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(7))
            ->delete();

        $requested = $request->integer('per_page');
        if ($request->has('per_page')) {
            $request->validate([
                'per_page' => ['required', 'integer', Rule::in(self::PAGE_SIZES)],
            ]);
            $perPage = $requested;
            if ((int) $user->notifications_per_page !== $perPage) {
                $user->update(['notifications_per_page' => $perPage]);
            }
        } else {
            $perPage = in_array((int) $user->notifications_per_page, self::PAGE_SIZES, true)
                ? (int) $user->notifications_per_page
                : 20;
        }

        return view('notifications.index', [
            'notifications' => $user->notifications()->latest()->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
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
        if ($item->read_at === null) {
            $item->markAsRead();
        }

        $url = $item->data['url'] ?? null;
        return $url ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request)
    {
        // Todas recebem o mesmo marco de leitura, iniciando aqui o prazo de
        // sete dias. Notificações ainda não lidas nunca expiram.
        DB::table('notifications')
            ->where('notifiable_type', $request->user()::class)
            ->where('notifiable_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Notificações marcadas como lidas.');
    }
}

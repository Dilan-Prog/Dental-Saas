<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\NotificationRecipient;
use App\Services\Dashboard\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        NotificationService::sync();

        $onlyUnread = $request->query('filtro') === 'no-leidas';
        $items = NotificationService::forUser($user)
            ->with('notification')
            ->when($onlyUnread, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('scheduled_for')
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', [
            'items' => $items,
            'onlyUnread' => $onlyUnread,
            'unread' => NotificationService::unreadCount($user),
        ]);
    }

    /** Marca una notificacion como leida y abre el registro relacionado. */
    public function read(Request $request, NotificationRecipient $recipient)
    {
        abort_unless($recipient->user_id === $request->user()->id, 404);

        NotificationService::markRead($recipient);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'unread' => NotificationService::unreadCount($request->user())]);
        }

        $url = $recipient->notification ? NotificationService::link($recipient->notification) : null;

        return $url ? redirect($url) : redirect()->back();
    }

    public function readAll(Request $request)
    {
        $count = NotificationService::markAllRead($request->user());

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'marked' => $count, 'unread' => 0]);
        }

        return redirect()->back()->with('success', $count > 0
            ? ($count === 1 ? '1 notificación marcada como leída.' : $count.' notificaciones marcadas como leídas.')
            : 'No tenías notificaciones sin leer.');
    }
}

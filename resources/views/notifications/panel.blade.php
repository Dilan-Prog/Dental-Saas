{{-- Panel de notificaciones del topbar (se incluye en TODAS las paginas autenticadas).
     Tambien monta el JS global del modulo Dashboard (buscador y contador de la campana). --}}
@php
    $__user = auth()->user();
    $__items = collect();
    $__unread = 0;
    if ($__user) {
        try {
            \App\Services\Dashboard\NotificationService::sync();
            $__items = \App\Services\Dashboard\NotificationService::recent($__user, 6);
            $__unread = \App\Services\Dashboard\NotificationService::unreadCount($__user);
        } catch (\Throwable $e) {
            report($e);
        }
    }
@endphp
<div class="notif-panel" data-notif-panel data-unread="{{ $__unread }}" data-search-url="{{ route('search') }}">
    <div class="notif-panel__bar">
        <span class="text-sm text-muted">{{ $__unread > 0 ? $__unread.' sin leer' : 'Estás al día' }}</span>
        @if ($__unread > 0)
            <form method="POST" action="{{ route('notifications.readAll') }}" data-no-lock>
                @csrf
                <button type="submit" class="link-btn">Marcar leídas</button>
            </form>
        @endif
    </div>

    @forelse ($__items as $r)
        @php($n = $r->notification)
        <form method="POST" action="{{ route('notifications.read', $r) }}" data-no-lock>
            @csrf
            <button type="submit" class="notif-item {{ $r->read_at ? 'is-read' : '' }}">
                <span class="notif-dot notif-dot--{{ \App\Services\Dashboard\NotificationService::kind($n->event_type) }}"></span>
                <span class="notif-item__body">
                    <span class="notif-item__title">{{ $n->title }}</span>
                    <span class="notif-item__time">{{ ($r->scheduled_for ?? $n->created_at)->diffForHumans() }}</span>
                </span>
            </button>
        </form>
    @empty
        <div class="empty" style="padding:24px 16px">
            <div class="empty__icon"><x-ui.icon name="bell" /></div>
            <div class="empty__title">Sin notificaciones</div>
            <div class="empty__text text-sm">Aquí verás recordatorios de citas, stock bajo y pagos.</div>
        </div>
    @endforelse

    <div class="notif-panel__foot"><a href="{{ route('notifications.index') }}">Ver todas</a></div>
</div>
<link rel="stylesheet" href="{{ asset('css/modules/notifications.css') }}?v={{ @filemtime(public_path('css/modules/notifications.css')) }}">
@push('scripts')
    <script src="{{ asset('js/modules/dashboard.js') }}?v={{ @filemtime(public_path('js/modules/dashboard.js')) }}" defer></script>
@endpush

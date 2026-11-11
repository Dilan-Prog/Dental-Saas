{{-- Listado completo de notificaciones del usuario. --}}
<x-app-layout title="Notificaciones" :breadcrumbs="['Notificaciones']">
    <x-ui.page-header title="Notificaciones" :subtitle="$unread > 0 ? $unread.' sin leer' : 'Estás al día'">
        <x-slot name="actions">
            <x-ui.button variant="secondary" href="{{ route('notifications.index', ['filtro' => $onlyUnread ? null : 'no-leidas']) }}">
                {{ $onlyUnread ? 'Ver todas' : 'Solo no leídas' }}
            </x-ui.button>
            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.readAll') }}" data-no-lock>
                    @csrf
                    <x-ui.button type="submit" icon="check">Marcar todas como leídas</x-ui.button>
                </form>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card flush>
        @forelse ($items as $r)
            @php($n = $r->notification)
            @continue(! $n)
            <form method="POST" action="{{ route('notifications.read', $r) }}" data-no-lock>
                @csrf
                <button type="submit" class="notif-item notif-item--page {{ $r->read_at ? 'is-read' : '' }}">
                    <span class="notif-dot notif-dot--{{ \App\Services\Dashboard\NotificationService::kind($n->event_type) }}"></span>
                    <span class="notif-item__body">
                        <span class="notif-item__title">{{ $n->title }}</span>
                        @if ($n->body)<span class="notif-item__sub">{{ $n->body }}</span>@endif
                        <span class="notif-item__time">{{ ($r->scheduled_for ?? $n->created_at)->translatedFormat('j M Y, H:i') }} · {{ ($r->scheduled_for ?? $n->created_at)->diffForHumans() }}</span>
                    </span>
                    @unless ($r->read_at)<x-ui.badge variant="primary">Nueva</x-ui.badge>@endunless
                </button>
            </form>
        @empty
            <x-ui.empty-state icon="bell" title="Sin notificaciones" :text="$onlyUnread ? 'No tienes notificaciones sin leer.' : 'Aquí verás recordatorios de citas, stock bajo y pagos.'" />
        @endforelse
    </x-ui.card>

    <div class="dash-mt">{{ $items->links() }}</div>
</x-app-layout>

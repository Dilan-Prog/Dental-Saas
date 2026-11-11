{{-- Topbar: breadcrumb, buscador global, tema, notificaciones y menú de usuario.
     Variables heredadas de layouts/app: $crumbsData, $crumbsHtml, $pageTitle, $notifCount, $searchUrl, $authUser.
     El panel de notificaciones se rellena con la vista opcional 'notifications.panel' (la crea el módulo Dashboard). --}}
@php
    $tbName = $authUser?->full_name ?? $authUser?->name ?? 'Invitado';
    $tbInitials = collect(preg_split('/\s+/', trim($tbName)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
    $crumbs = [];
    if (is_array($crumbsData)) {
        foreach ($crumbsData as $k => $v) { is_int($k) ? $crumbs[] = [$v, null] : $crumbs[] = [$k, $v]; }
    } elseif ($pageTitle !== '') {
        $crumbs[] = [$pageTitle, null];
    }
@endphp
<header class="topbar">
    <div class="topbar__left">
        <button type="button" class="icon-btn menu-toggle" data-sidebar-toggle aria-label="Abrir menú"><x-ui.icon name="menu" /></button>
        @if ($crumbsHtml !== '')
            <nav class="breadcrumb" aria-label="Migas de pan">{!! $crumbsHtml !!}</nav>
        @else
            <nav class="breadcrumb" aria-label="Migas de pan">
                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="crumb-extra">Inicio</a>
                @foreach ($crumbs as [$label, $url])
                    <x-ui.icon name="chevron-right" class="crumb-extra" />
                    @if ($url && ! $loop->last)
                        <a href="{{ $url }}" class="crumb-extra">{{ $label }}</a>
                    @else
                        <span class="is-current truncate">{{ $label }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
    </div>

    <div class="topbar__right">
        <form action="{{ $searchUrl }}" method="GET" class="search" role="search" data-no-lock>
            <div class="input-wrap">
                <x-ui.icon name="search" size="sm" />
                <input type="search" name="q" class="input" placeholder="Buscar paciente, cita, factura..." value="{{ request('q') }}" data-global-search aria-label="Buscar">
            </div>
        </form>

        <button type="button" class="icon-btn" data-theme-toggle aria-label="Cambiar tema">
            <x-ui.icon name="moon" class="theme-moon" />
            <x-ui.icon name="sun" class="theme-sun" />
        </button>

        <div class="dropdown" data-dropdown>
            <button type="button" class="icon-btn" data-dropdown-toggle aria-label="Notificaciones" aria-expanded="false">
                <x-ui.icon name="bell" />
                @if ($notifCount > 0)<span class="dot-count">{{ $notifCount > 9 ? '9+' : $notifCount }}</span>@endif
            </button>
            <div class="dropdown-menu dropdown-menu--wide">
                <div class="dropdown-header fw-700">Notificaciones</div>
                @includeFirst(['notifications.panel', 'layouts.partials.notifications-empty'])
            </div>
        </div>

        <div class="dropdown" data-dropdown>
            <button type="button" class="avatar" data-dropdown-toggle aria-label="Menú de usuario" aria-expanded="false" style="border:0">{{ $tbInitials ?: 'U' }}</button>
            <div class="dropdown-menu">
                <div class="dropdown-header"><div class="fw-600" style="color:var(--text)">{{ $tbName }}</div><div>{{ $authUser?->email }}</div></div>
                <div class="dropdown-sep"></div>
                @if (Route::has('profile.edit'))
                    <a href="{{ route('profile.edit') }}" class="dropdown-item"><x-ui.icon name="user" /> Mi perfil</a>
                @endif
                @if (Route::has('logout'))
                    <form method="POST" action="{{ route('logout') }}" data-no-lock>
                        @csrf
                        <button type="submit" class="dropdown-item dropdown-item--danger"><x-ui.icon name="log-out" /> Cerrar sesión</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</header>

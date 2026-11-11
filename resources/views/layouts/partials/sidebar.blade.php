{{-- Sidebar generado desde config/menu.php (ver ese archivo para el formato de cada ítem). --}}
@php
    $u = auth()->user();
    $userName = $u?->full_name ?? $u?->name ?? 'Invitado';
    $initials = collect(preg_split('/\s+/', trim($userName)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
    try { $roleName = $u?->role?->name; } catch (\Throwable $e) { $roleName = null; }
    $can = function (string $module) use ($u) {
        if (! $u) { return true; } // sin sesión (p. ej. /_styleguide): mostrar todo
        return method_exists($u, 'hasPermission') ? $u->hasPermission($module, 'ver') : true;
    };
@endphp
<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="sidebar__brand">
        <x-ui.logo :size="28" />
        <span>Dental <b>SaaS</b></span>
    </a>

    <nav class="sidebar__nav">
        @foreach (config('menu.sections', []) as $section)
            @php $items = collect($section['items'])->filter(fn ($i) => $can($i['module'] ?? 'dashboard')); @endphp
            @if ($items->isNotEmpty())
                <div class="nav-group">
                    <div class="nav-section">{{ $section['title'] }}</div>
                    @foreach ($items as $item)
                        @php
                            $href = Route::has($item['route']) ? route($item['route']) : '#';
                            $active = ! empty($item['active']) && request()->routeIs(...$item['active']);
                        @endphp
                        <a href="{{ $href }}" class="nav-item {{ $active ? 'is-active' : '' }}" title="{{ $item['label'] }}" @if($active) aria-current="page" @endif>
                            <x-ui.icon :name="$item['icon']" />
                            <span class="nav-item__label">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </nav>

    <div class="sidebar__footer">
        <span class="avatar">{{ $initials ?: 'U' }}</span>
        <div class="sidebar__user">
            <div class="sidebar__user-name truncate">{{ $userName }}</div>
            @if ($roleName)<div class="sidebar__user-role truncate">{{ $roleName }}</div>@endif
        </div>
    </div>
</aside>

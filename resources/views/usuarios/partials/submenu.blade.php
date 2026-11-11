{{--
  Submenú de Configuración (Figma: Clínica · Usuarios · Roles y permisos · Tratamientos ·
  Facturación · Notificaciones · Sucursales · Perfil). Solo muestra los destinos cuyas rutas
  existen y a los que el usuario tiene permiso (los otros módulos las irán añadiendo).
--}}
@php
    $u = auth()->user();
    $entries = [
        ['label' => 'Clínica',           'icon' => 'building', 'routes' => ['configuracion.clinica', 'configuracion.index'], 'module' => 'configuracion', 'active' => ['configuracion.clinica', 'configuracion.index']],
        ['label' => 'Usuarios',          'icon' => 'users',    'routes' => ['usuarios.index'],             'module' => 'usuarios',      'active' => ['usuarios.*']],
        ['label' => 'Roles y permisos',  'icon' => 'shield',   'routes' => ['roles.index'],                'module' => 'roles',         'active' => ['roles.*']],
        ['label' => 'Tratamientos',      'icon' => 'tooth',    'routes' => ['tratamientos.index'],         'module' => 'tratamientos',  'active' => ['tratamientos.*']],
        ['label' => 'Facturación',       'icon' => 'receipt',  'routes' => ['configuracion.facturacion'],  'module' => 'configuracion', 'active' => ['configuracion.facturacion']],
        ['label' => 'Notificaciones',    'icon' => 'bell',     'routes' => ['configuracion.notificaciones'], 'module' => 'configuracion', 'active' => ['configuracion.notificaciones']],
        ['label' => 'Sucursales',        'icon' => 'map-pin',  'routes' => ['configuracion.sucursales'],   'module' => 'configuracion', 'active' => ['configuracion.sucursales']],
        ['label' => 'Perfil',            'icon' => 'user',     'routes' => ['profile.edit'],               'module' => null,            'active' => ['profile.*']],
    ];
@endphp
<nav class="usr-submenu" aria-label="Configuración">
    @foreach ($entries as $e)
        @php
            $routeName = collect($e['routes'])->first(fn ($r) => Route::has($r));
            $allowed = $e['module'] === null || ($u && $u->hasPermission($e['module'], 'ver'));
        @endphp
        @if ($routeName && $allowed)
            <a href="{{ route($routeName) }}" class="usr-submenu__link {{ request()->routeIs(...$e['active']) ? 'is-active' : '' }}">
                <x-ui.icon :name="$e['icon']" size="sm" />{{ $e['label'] }}
            </a>
        @endif
    @endforeach
</nav>

{{-- Sub-menú de Configuración (Figma: Clínica, Usuarios y Roles, Tratamientos, Facturación, Notificaciones, Sucursales, Mi perfil).
     Cada entrada usa la primera ruta que exista, así este módulo no depende de los nombres que elija Configuración. --}}
@php
    $pick = function (array $names) {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return '#';
    };
    $entries = [
        ['Clínica', 'building', ['configuracion.index', 'configuracion.clinica'], false],
        ['Usuarios y Roles', 'users', ['usuarios.index', 'configuracion.usuarios'], false],
        ['Tratamientos', 'tooth', ['tratamientos.index'], true],
        ['Facturación', 'receipt', ['configuracion.facturacion'], false],
        ['Notificaciones', 'bell', ['configuracion.notificaciones'], false],
        ['Sucursales', 'map-pin', ['configuracion.sucursales'], false],
        ['Mi perfil', 'user', ['configuracion.perfil', 'profile.edit'], false],
    ];
@endphp
<nav class="settings-menu" aria-label="Configuración">
    @foreach ($entries as [$label, $icon, $routes, $active])
        <a href="{{ $pick($routes) }}" class="settings-menu__item {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif>
            <x-ui.icon :name="$icon" size="sm" />{{ $label }}
        </a>
    @endforeach
</nav>

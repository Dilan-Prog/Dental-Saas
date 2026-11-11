{{--
  Submenú lateral ÚNICO de Configuración (Figma: Clínica · Usuarios · Roles y permisos ·
  Tratamientos · Facturación · Notificaciones · Sucursales [Owner] · Mi perfil).
  Lo usan Configuración, Usuarios, Roles y Tratamientos para verse idénticos.
  Variables: $section = clinica|usuarios|roles|tratamientos|facturacion|notificaciones|sucursales|perfil
  Requiere public/css/modules/configuracion.css (clases .cfg-nav*).
--}}
@php
    $cfgUser = auth()->user();
    $permByKey = [
        'clinica' => 'configuracion', 'facturacion' => 'configuracion', 'notificaciones' => 'configuracion', 'sucursales' => 'configuracion',
        'usuarios' => 'usuarios', 'roles' => 'roles', 'tratamientos' => 'tratamientos', 'perfil' => null,
    ];
    $cfgItems = collect(\App\Services\Configuracion\ConfiguracionCatalog::sections())->filter(function ($item) use ($cfgUser, $permByKey) {
        if (($item['owner'] ?? false) && ! $cfgUser->isAdmin()) {
            return false;
        }
        $module = $permByKey[$item['key']] ?? null;

        return $module === null || $cfgUser->hasPermission($module, 'ver');
    });
@endphp
<aside class="cfg-nav" aria-label="Secciones de configuración">
    <div class="cfg-nav__head">
        <div class="cfg-nav__title">Configuración</div>
        <div class="cfg-nav__subtitle">Administra tu clínica</div>
    </div>
    <nav class="cfg-nav__list">
        @foreach ($cfgItems as $item)
            @php $isActive = ($section ?? null) === $item['key']; @endphp
            @if ($item['route'])
                <a href="{{ route($item['route']) }}" class="cfg-nav__item {{ $isActive ? 'is-active' : '' }}" @if($isActive) aria-current="page" @endif>
                    <span>{{ $item['label'] }}</span>
                    @if ($item['owner'] ?? false)<span class="cfg-tag cfg-tag--owner">Owner</span>@endif
                </a>
            @else
                <span class="cfg-nav__item is-disabled" aria-disabled="true" title="Disponible próximamente"><span>{{ $item['label'] }}</span></span>
            @endif
        @endforeach
    </nav>
</aside>

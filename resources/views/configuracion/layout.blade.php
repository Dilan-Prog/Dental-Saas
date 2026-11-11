{{--
  Layout del módulo Configuración: submenú lateral (según Figma) + contenido.
  Las vistas hijas usan: @extends('configuracion.layout') y @section('cfg') ... @endsection
  Variables: $section (clinica|facturacion|notificaciones|sucursales|perfil).
--}}
@extends('layouts.app')

@section('title', 'Configuración')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/configuracion.css') }}?v={{ @filemtime(public_path('css/modules/configuracion.css')) }}">
@endpush

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

@section('content')
    <div class="cfg">
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

        <section class="cfg-main">
            @yield('cfg')
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/configuracion.js') }}?v={{ @filemtime(public_path('js/modules/configuracion.js')) }}"></script>
@endpush

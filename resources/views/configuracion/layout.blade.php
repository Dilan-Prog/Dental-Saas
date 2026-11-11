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

@section('content')
    <div class="cfg">
        @include('configuracion.partials.nav', ['section' => $section ?? null])

        <section class="cfg-main">
            @yield('cfg')
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/configuracion.js') }}?v={{ @filemtime(public_path('js/modules/configuracion.js')) }}"></script>
@endpush

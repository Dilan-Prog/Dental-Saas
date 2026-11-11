{{--
  LAYOUT PRINCIPAL (shell con sidebar + topbar).

  Uso A (herencia):
      @extends('layouts.app')
      @section('title', 'Pacientes')
      @section('breadcrumbs') ... @endsection   (opcional, HTML libre)
      @push('styles')  <link rel="stylesheet" href="{{ asset('css/modules/pacientes.css') }}"> @endpush
      @section('content') ... @endsection
      @push('scripts') <script src="{{ asset('js/modules/pacientes.js') }}"></script> @endpush

  Uso B (componente):
      <x-app-layout title="Pacientes" :breadcrumbs="['Pacientes' => route('pacientes.index'), 'Nuevo']">
          ...contenido...
      </x-app-layout>
      (también acepta <x-slot name="title"> y <x-slot name="breadcrumbs">)

  Breadcrumbs: array de 'Etiqueta' => url|null (o lista de strings). El último es la página actual.
  Variable opcional: $notificationCount (contador de la campana).
--}}
@php
    $attrs = isset($attributes) ? $attributes : null;
    $pageTitle = isset($title) ? (string) $title : ($attrs && $attrs->get('title') ? (string) $attrs->get('title') : trim($__env->yieldContent('title')));
    $crumbsData = isset($breadcrumbs) && is_array($breadcrumbs) ? $breadcrumbs : ($attrs && is_array($attrs->get('breadcrumbs')) ? $attrs->get('breadcrumbs') : null);
    $crumbsHtml = isset($breadcrumbs) && !is_array($breadcrumbs) ? (string) $breadcrumbs : trim($__env->yieldContent('breadcrumbs'));
    $authUser = auth()->user();
    $notifCount = (int) ($notificationCount ?? 0);
    $searchUrl = Route::has('pacientes.index') ? route('pacientes.index') : '#';

    $flash = [];
    foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] as $key => $type) {
        if (session()->has($key)) { $flash[] = ['type' => $type, 'message' => (string) session($key)]; }
    }
    $statusMap = ['profile-updated' => 'Perfil actualizado correctamente.', 'password-updated' => 'Contraseña actualizada correctamente.'];
    if (session()->has('status')) {
        $st = (string) session('status');
        $flash[] = ['type' => 'success', 'message' => $statusMap[$st] ?? $st];
    }
    if ($errors->any()) {
        $flash[] = ['type' => 'error', 'title' => 'Revisa el formulario', 'message' => $errors->count() === 1 ? $errors->first() : 'Hay '.$errors->count().' campos con errores.'];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle !== '' ? $pageTitle.' · ' : '' }}Dental SaaS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>try{var t=localStorage.getItem('theme');if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';document.documentElement.setAttribute('data-theme',t||'light')}catch(e){}</script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    @stack('styles')
</head>
<body>
<div class="app">
    @include('layouts.partials.sidebar')
    <div class="sidebar-backdrop" data-sidebar-toggle></div>

    <div class="main">
        @include('layouts.partials.topbar')
        <main class="content">
            @isset($header)
                <div class="mb-6">{{ $header }}</div>
            @endisset
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

<div id="toast-root" class="toast-root" aria-live="polite"></div>
<script>window.__flash = @json($flash);</script>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>

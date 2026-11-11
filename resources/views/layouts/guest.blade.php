{{--
  LAYOUT INVITADO (login, recuperar contraseña). Marca centrada + tarjeta.
  Uso: <x-guest-layout> ... </x-guest-layout>   o   @extends('layouts.guest') @section('content')
  Opcional: título con <x-guest-layout title="Iniciar sesión"> o @section('title').
--}}
@php
    $attrs = isset($attributes) ? $attributes : null;
    $pageTitle = isset($title) ? (string) $title : ($attrs && $attrs->get('title') ? (string) $attrs->get('title') : trim($__env->yieldContent('title')));
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
<div class="auth-shell">
    <a href="{{ url('/') }}" class="auth-brand" style="text-decoration:none;color:inherit">
        <x-ui.logo :size="40" />
        <span>Dental <b>SaaS</b></span>
    </a>
    <div class="auth-card">
        {{ $slot ?? '' }}
        @yield('content')
    </div>
    <div class="auth-foot">&copy; {{ date('Y') }} Dental SaaS</div>
</div>
<div id="toast-root" class="toast-root" aria-live="polite"></div>
<script>window.__flash = [];</script>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>

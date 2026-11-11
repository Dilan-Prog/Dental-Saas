<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dental SaaS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="welcome">
    <div class="welcome__box">
        <x-ui.logo :size="56" />
        <h1>Dental <span style="color:var(--primary)">SaaS</span></h1>
        <p class="text-muted">Gestión integral de tu consultorio dental: agenda, expedientes, recetas, facturación e inventario.</p>
        @auth
            <x-ui.button :href="route('dashboard')" size="lg">Ir al panel</x-ui.button>
        @else
            <x-ui.button :href="route('login')" size="lg">Iniciar sesión</x-ui.button>
        @endauth
    </div>
</div>
</body>
</html>

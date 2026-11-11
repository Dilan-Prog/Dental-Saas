<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · Dental SaaS</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        /* Respaldo mínimo si app.css aún no existe */
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #f5f8fb; color: #0f172a; }
        .err-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
        .err-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px 32px; max-width: 440px; width: 100%; text-align: center; }
        .err-code { font-size: 56px; font-weight: 700; color: #2563eb; line-height: 1; }
        .err-title { font-size: 20px; font-weight: 600; margin: 16px 0 8px; }
        .err-msg { color: #64748b; font-size: 14px; margin: 0 0 24px; }
        .err-btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; }
    </style>
</head>
<body>
    <div class="err-wrap">
        <div class="err-card">
            <div class="err-code">@yield('code')</div>
            <h1 class="err-title">@yield('title')</h1>
            <p class="err-msg">@yield('message')</p>
            <a class="err-btn" href="{{ auth()->check() ? url('/dashboard') : url('/') }}">Volver al inicio</a>
        </div>
    </div>
</body>
</html>

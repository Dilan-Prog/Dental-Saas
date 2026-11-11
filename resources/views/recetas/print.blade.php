<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- El título es el nombre sugerido al "Guardar como PDF". --}}
    <title>Receta {{ $rx->folio }} - {{ $rx->patient?->full_name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/modules/recetas.css') }}?v={{ @filemtime(public_path('css/modules/recetas.css')) }}">
</head>
<body class="rx-print-page">
    <div class="rx-print-bar no-print">
        <div class="rx-print-bar__text">
            <strong>{{ $rx->folio }}</strong>
            @if ($rx->isSigned())
                <span class="badge badge--success">Firmada</span>
            @else
                <span class="badge badge--warning">Borrador</span>
            @endif
            @if ($integrity === false)
                <span class="badge badge--danger">El contenido no coincide con el hash de firma</span>
            @endif
        </div>
        <div class="row">
            <a class="btn btn--secondary btn--sm" href="{{ route('recetas.show', $rx) }}">Volver</a>
            <button type="button" class="btn btn--primary btn--sm" data-print>Imprimir / Guardar como PDF</button>
        </div>
    </div>

    <main class="rx-print-sheet">
        @include('recetas._document', ['rx' => $rx, 'org' => $org])
    </main>

    <script>
        document.querySelector('[data-print]').addEventListener('click', function () { window.print(); });
        @if ($autoPrint)
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
        @endif
    </script>
</body>
</html>

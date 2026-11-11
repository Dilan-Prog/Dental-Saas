{{-- Resultados del buscador global. --}}
<x-app-layout title="Búsqueda" :breadcrumbs="['Búsqueda']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/dashboard.css') }}?v={{ @filemtime(public_path('css/modules/dashboard.css')) }}">
    @endpush

    <x-ui.page-header title="Búsqueda" :subtitle="$term !== '' && ! $tooShort ? $total.' resultado'.($total === 1 ? '' : 's').' para “'.$term.'”' : 'Busca pacientes, citas, facturas y recetas'" />

    <form method="GET" action="{{ route('search') }}" class="search-page" data-no-lock>
        <x-ui.input name="q" :value="$term" icon="search" placeholder="Buscar paciente, cita, factura, receta..." maxlength="100" autofocus />
        <x-ui.button type="submit">Buscar</x-ui.button>
    </form>

    <div class="stack stack--lg dash-mt">
        @if ($term === '')
            <x-ui.card><x-ui.empty-state icon="search" title="Escribe para buscar" text="Usa al menos 2 caracteres: nombre, folio, teléfono o correo." /></x-ui.card>
        @elseif ($tooShort)
            <x-ui.card><x-ui.empty-state icon="info" title="Búsqueda muy corta" text="Escribe al menos 2 caracteres." /></x-ui.card>
        @elseif ($total === 0)
            <x-ui.card><x-ui.empty-state icon="search" title="Sin resultados" :text="'No encontramos coincidencias para “'.$term.'”.'" /></x-ui.card>
        @else
            @foreach ($groups as $g)
                @continue(count($g['items']) === 0)
                <x-ui.card :title="$g['title']" flush>
                    @foreach ($g['items'] as $item)
                        @if ($item['url'])
                            <a class="result" href="{{ $item['url'] }}">
                        @else
                            <div class="result">
                        @endif
                            <span class="result__label">{{ $item['label'] }}</span>
                            @if ($item['sub'])<span class="result__sub">{{ $item['sub'] }}</span>@endif
                        @if ($item['url'])</a>@else</div>@endif
                    @endforeach
                </x-ui.card>
            @endforeach
        @endif
    </div>
</x-app-layout>

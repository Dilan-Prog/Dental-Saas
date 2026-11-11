@php
    use App\Enums\QuoteStatus;
    use App\Support\Money;

    $badge = ['borrador' => 'neutral', 'enviado' => 'info', 'aprobado' => 'success', 'rechazado' => 'danger'];
    $query = fn (array $extra = []) => array_filter(array_merge(request()->except('page', 'estado'), $extra), fn ($v) => $v !== null && $v !== '');
    $tabs = [
        '' => ['label' => 'Todos', 'href' => route('presupuestos.index', $query()), 'count' => $counts->sum()],
        'borrador' => ['label' => 'Borrador', 'href' => route('presupuestos.index', $query(['estado' => 'borrador'])), 'count' => $counts['borrador'] ?? 0],
        'enviado' => ['label' => 'Enviados', 'href' => route('presupuestos.index', $query(['estado' => 'enviado'])), 'count' => $counts['enviado'] ?? 0],
        'aprobado' => ['label' => 'Aprobados', 'href' => route('presupuestos.index', $query(['estado' => 'aprobado'])), 'count' => $counts['aprobado'] ?? 0],
        'rechazado' => ['label' => 'Rechazados', 'href' => route('presupuestos.index', $query(['estado' => 'rechazado'])), 'count' => $counts['rechazado'] ?? 0],
    ];
    $hasFilters = $filters['q'] || $filters['desde'] || $filters['hasta'] || $filters['paciente'];
@endphp
<x-app-layout title="Presupuestos" :breadcrumbs="['Presupuestos']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/presupuestos.css') }}">
    @endpush

    <x-ui.page-header title="Presupuestos" subtitle="Plan de tratamiento y cotizaciones">
        <x-slot name="actions">
            @if (auth()->user()->hasPermission('tratamientos', 'ver'))
                <x-ui.button variant="secondary" icon="tooth" :href="route('tratamientos.index')">Catálogo de tratamientos</x-ui.button>
            @endif
            @if (auth()->user()->hasPermission('presupuestos', 'crear'))
                <x-ui.button icon="plus" :href="route('presupuestos.create')">Nuevo presupuesto</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs :items="$tabs" :active="$estado ?? ''" />

    <form method="GET" action="{{ route('presupuestos.index') }}" class="quotes-filters">
        @if ($estado)<input type="hidden" name="estado" value="{{ $estado }}">@endif
        @if ($filters['paciente'])<input type="hidden" name="paciente" value="{{ $filters['paciente'] }}">@endif
        <x-ui.input name="q" :value="$filters['q']" icon="search" placeholder="Buscar por folio o paciente…" maxlength="100" aria-label="Buscar" />
        <label class="quotes-filters__date"><span class="text-xs text-muted">Desde</span>
            <x-ui.input type="date" name="desde" :value="$filters['desde']" /></label>
        <label class="quotes-filters__date"><span class="text-xs text-muted">Hasta</span>
            <x-ui.input type="date" name="hasta" :value="$filters['hasta']" /></label>
        <x-ui.button type="submit" variant="secondary">Filtrar</x-ui.button>
        @if ($hasFilters)
            <x-ui.button variant="ghost" :href="route('presupuestos.index', array_filter(['estado' => $estado]))">Limpiar</x-ui.button>
        @endif
    </form>

    @if ($filterPatient)
        <p class="text-sm text-muted mb-2">Mostrando presupuestos de <strong>{{ $filterPatient->full_name }}</strong>.</p>
    @endif

    <x-ui.card flush>
        <x-ui.table clickable>
            <x-slot name="head">
                <tr><th>Folio</th><th>Paciente</th><th>Doctor</th><th>Total</th><th>Estado</th><th>Fecha</th><th class="actions"></th></tr>
            </x-slot>
            @forelse ($quotes as $quote)
                <tr data-href="{{ route('presupuestos.show', $quote) }}">
                    <td class="fw-500 nowrap"><a href="{{ route('presupuestos.show', $quote) }}">{{ $quote->folio }}</a></td>
                    <td>{{ $quote->patient?->full_name ?? '—' }}</td>
                    <td class="text-muted">{{ $quote->doctor?->name ?? '—' }}</td>
                    <td class="fw-500 mono nowrap">{{ Money::format($quote->total) }}</td>
                    <td><x-ui.badge :variant="$badge[$quote->status->value]">{{ $quote->status->label() }}</x-ui.badge></td>
                    <td class="text-muted nowrap">{{ $quote->quote_date->translatedFormat('j M Y') }}</td>
                    <td class="actions"><x-ui.button size="sm" variant="ghost" :href="route('presupuestos.show', $quote)">Ver</x-ui.button></td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-ui.empty-state icon="file-text" :title="$hasFilters || $estado ? 'Sin resultados' : 'Aún no hay presupuestos'"
                        :text="$hasFilters || $estado ? 'Prueba con otros filtros.' : 'Crea el primer presupuesto para un paciente.'">
                        @if (! $hasFilters && ! $estado && auth()->user()->hasPermission('presupuestos', 'crear'))
                            <x-ui.button icon="plus" :href="route('presupuestos.create')">Nuevo presupuesto</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                </td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="pagination-wrap">{{ $quotes->links() }}</div>

    @push('scripts')
        <script src="{{ asset('js/modules/presupuestos.js') }}"></script>
    @endpush
</x-app-layout>

@extends('layouts.app')

@section('title', 'Inventario')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/inventario.css') }}">
@endpush

@php
    $fmt = new \App\Services\Inventario\InventarioFormat();

    $isMovs = $f['vista'] === 'transacciones';
    $keep = array_filter(['sucursal' => $f['branch']]);
    $multiBranch = $branches->count() > 1;
    $branchOptions = ['all' => 'Todas las sucursales'] + $branches->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    $hasFilters = $isMovs
        ? ($f['q'] !== '' || $f['type'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['item'] !== '')
        : ($f['q'] !== '' || $f['category'] !== '' || $f['supplier'] !== '' || $f['filter'] !== '' || $f['status'] !== 'active');
    $exportQuery = array_filter(array_merge(['vista' => $f['vista']], request()->query()));
    $typeBadge = [
        'inventario_inicial' => 'neutral',
        'entrada' => 'success',
        'salida' => 'warning',
        'ajuste' => 'info',
        'reversion' => 'danger',
    ];
@endphp

@section('content')
    <x-ui.page-header title="Inventario" :subtitle="$kpis['count'] . ' insumos · valor ' . $fmt::money($kpis['value'])">
        <x-slot name="actions">
            <div class="segmented" role="tablist" aria-label="Vista">
                <a href="{{ route('inventario.index', $keep) }}" class="{{ ! $isMovs ? 'is-active' : '' }}">Resumen</a>
                <a href="{{ route('inventario.index', $keep + ['vista' => 'transacciones']) }}" class="{{ $isMovs ? 'is-active' : '' }}">Transacciones</a>
            </div>
            <x-ui.button variant="ghost" icon="package" :href="route('inventario.proveedores.index')">Proveedores</x-ui.button>
            @if ($can['export'])
                <x-ui.button variant="secondary" icon="download" :href="route('inventario.export', $exportQuery)">CSV</x-ui.button>
            @endif
            @if ($can['create'])
                <x-ui.button variant="secondary" icon="download" data-modal-open="modal-entrada">Entrada</x-ui.button>
                <x-ui.button variant="secondary" icon="upload" data-modal-open="modal-salida">Salida</x-ui.button>
                <x-ui.button variant="secondary" icon="edit" data-modal-open="modal-ajuste">Ajuste</x-ui.button>
                <x-ui.button icon="plus" data-modal-open="modal-nuevo-insumo">Nuevo insumo</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="stack">
        @if ($kpis['low'] > 0)
            <x-ui.alert variant="warning" :title="$kpis['low'] . ($kpis['low'] === 1 ? ' insumo por debajo del stock mínimo' : ' insumos por debajo del stock mínimo')">
                Considera generar una orden de compra.
                <a href="{{ route('inventario.index', $keep + ['filter' => 'bajo']) }}" class="fw-600">Ver insumos</a>
            </x-ui.alert>
        @endif
        @if ($kpis['expiring'] + $kpis['expired'] > 0)
            <x-ui.alert variant="danger" title="Lotes por caducar">
                {{ $kpis['expired'] }} {{ $kpis['expired'] === 1 ? 'vencido' : 'vencidos' }} y {{ $kpis['expiring'] }} que {{ $kpis['expiring'] === 1 ? 'caduca' : 'caducan' }} en los próximos {{ \App\Services\Inventario\InventarioReport::EXPIRY_DAYS }} días.
                <a href="{{ route('inventario.index', $keep + ['filter' => 'caducan']) }}" class="fw-600">Ver insumos</a>
            </x-ui.alert>
        @endif

        @unless ($isMovs)
            <div class="grid grid-4 inv-kpis">
                <x-ui.kpi label="Insumos activos" :value="$kpis['count']" />
                <x-ui.kpi label="Valor del inventario" :value="$fmt::money($kpis['value'])" delta="Costo promedio ponderado" />
                <x-ui.kpi label="Bajo mínimo" :value="$kpis['low']" :delta="$kpis['out'] . ($kpis['out'] === 1 ? ' agotado' : ' agotados')" :trend="$kpis['low'] > 0 ? 'down' : null" />
                <x-ui.kpi label="Por caducar" :value="$kpis['expiring']" :delta="$kpis['expired'] . ($kpis['expired'] === 1 ? ' vencido' : ' vencidos') . ' · próximos ' . \App\Services\Inventario\InventarioReport::EXPIRY_DAYS . ' días'" :trend="$kpis['expired'] > 0 ? 'down' : null" />
            </div>
        @endunless

        <x-ui.card flush>
            {{-- ---------------------------- Filtros ---------------------------- --}}
            <form method="GET" action="{{ route('inventario.index') }}" class="table-toolbar inv-toolbar" data-autosubmit>
                @if ($isMovs)<input type="hidden" name="vista" value="transacciones">@endif
                <div class="input-wrap">
                    <x-ui.icon name="search" size="sm" />
                    <input class="input" type="search" name="q" value="{{ $f['q'] }}" maxlength="100"
                           placeholder="{{ $isMovs ? 'Buscar por insumo o SKU...' : 'Buscar por nombre o SKU...' }}" aria-label="Buscar">
                </div>
                <div class="row row--wrap inv-filters">
                    @if ($multiBranch)
                        <x-ui.select name="sucursal" :options="$branchOptions" :selected="$f['branch']" aria-label="Sucursal" />
                    @endif
                    @if ($isMovs)
                        <x-ui.select name="type" :options="\App\Enums\InventoryMovementType::options()" :selected="$f['type']" placeholder="Todos los tipos" aria-label="Tipo" />
                        <input class="input inv-date" type="date" name="from" value="{{ $f['from'] }}" aria-label="Desde" title="Desde">
                        <input class="input inv-date" type="date" name="to" value="{{ $f['to'] }}" aria-label="Hasta" title="Hasta">
                    @else
                        <x-ui.select name="category" :options="$categories->mapWithKeys(fn ($c) => [$c => $c])->all()" :selected="$f['category']" placeholder="Todas las categorías" aria-label="Categoría" />
                        <x-ui.select name="supplier" :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()" :selected="$f['supplier']" placeholder="Todos los proveedores" aria-label="Proveedor" />
                        <x-ui.select name="filter" :options="['bajo' => 'Bajo mínimo', 'agotado' => 'Agotados', 'caducan' => 'Por caducar']" :selected="$f['filter']" placeholder="Todo el stock" aria-label="Estado de stock" />
                        <x-ui.select name="status" :options="['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos']" :selected="$f['status']" aria-label="Estado" />
                    @endif
                    <noscript><x-ui.button type="submit" variant="secondary" size="sm">Filtrar</x-ui.button></noscript>
                    @if ($hasFilters)
                        <x-ui.button variant="ghost" size="sm" icon="x" :href="route('inventario.index', $keep + ($isMovs ? ['vista' => 'transacciones'] : []))">Limpiar</x-ui.button>
                    @endif
                </div>
            </form>

            @if (! $isMovs)
                {{-- ---------------------------- Resumen ---------------------------- --}}
                <x-ui.table clickable class="inv-table">
                    <x-slot name="head">
                        <tr>
                            <th>Insumo</th><th>SKU</th><th>Categoría</th><th>Stock</th>
                            <th class="text-right">Mínimo</th><th class="text-right">Costo uni.</th><th>Ubicación</th>
                        </tr>
                    </x-slot>
                    @forelse ($items as $i)
                        @php
                            $out = (bool) $i->is_out;
                            $low = (bool) $i->is_low;
                            $inactive = $i->status === \App\Enums\RecordStatus::Inactive;
                        @endphp
                        <tr class="inv-row {{ $out ? 'inv-row--out' : ($low ? 'inv-row--low' : '') }} {{ $inactive ? 'inv-row--inactive' : '' }}"
                            data-href="{{ route('inventario.items.show', $i) }}">
                            <td>
                                <a class="fw-600 inv-name" href="{{ route('inventario.items.show', $i) }}">{{ $i->name }}</a>
                                @if ($inactive)<x-ui.badge variant="neutral">Inactivo</x-ui.badge>@endif
                                <div class="text-muted text-xs">{{ $i->supplier?->name ?? 'Sin proveedor' }}</div>
                            </td>
                            <td class="mono">{{ $i->sku }}</td>
                            <td>@if ($i->category)<span class="badge badge--neutral inv-cat">{{ $i->category }}</span>@else<span class="text-subtle">—</span>@endif</td>
                            <td>
                                <span class="inv-stock {{ $out ? 'inv-stock--out' : ($low ? 'inv-stock--low' : '') }}">
                                    {{ $fmt::qty($i->stock_qty) }} {{ $i->unit_of_measure }}
                                    @if ($low || $out)<x-ui.icon name="bell" size="sm" />@endif
                                </span>
                            </td>
                            <td class="text-right mono text-muted">{{ $fmt::qty($i->min_qty) }}</td>
                            <td class="text-right mono fw-600">{{ $fmt::cost($i->unit_cost) }}</td>
                            <td>{{ $i->locations ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">
                            @if ($hasFilters)
                                <x-ui.empty-state icon="search" title="Sin resultados" text="Ningún insumo coincide con los filtros." />
                            @else
                                <x-ui.empty-state icon="package" title="Aún no hay insumos" text="Registra el primer insumo para controlar tu inventario.">
                                    @if ($can['create'])<x-ui.button icon="plus" data-modal-open="modal-nuevo-insumo">Nuevo insumo</x-ui.button>@endif
                                </x-ui.empty-state>
                            @endif
                        </td></tr>
                    @endforelse
                </x-ui.table>
                <div class="table-foot">
                    <span>{{ $items->total() }} {{ $items->total() === 1 ? 'insumo' : 'insumos' }}</span>
                    <div class="pagination-wrap">{{ $items->links() }}</div>
                </div>
            @else
                {{-- ------------------------- Transacciones ------------------------- --}}
                <x-ui.table class="inv-table">
                    <x-slot name="head">
                        <tr>
                            <th>Fecha</th><th>Insumo</th><th>Tipo</th><th class="text-right">Cantidad</th>
                            <th class="text-right">Costo uni.</th>@if ($multiBranch)<th>Sucursal</th>@endif<th>Detalle</th><th>Usuario</th><th class="actions"></th>
                        </tr>
                    </x-slot>
                    @forelse ($movements as $m)
                        @php
                            $neg = (float) $m->quantity < 0;
                            $reversible = $can['edit']
                                && ! in_array($m->movement_type->value, ['reversion', 'inventario_inicial'], true)
                                && ! $m->reversal_exists;
                        @endphp
                        <tr>
                            <td class="nowrap">{{ $m->occurred_at->format('d/m/Y H:i') }}</td>
                            <td>
                                @if ($m->item)
                                    <a class="fw-600" href="{{ route('inventario.items.show', $m->item) }}">{{ $m->item->name }}</a>
                                    <div class="text-muted text-xs mono">{{ $m->item->sku }}</div>
                                @else — @endif
                            </td>
                            <td><x-ui.badge :variant="$typeBadge[$m->movement_type->value] ?? 'neutral'">{{ $m->movement_type->label() }}</x-ui.badge></td>
                            <td class="text-right mono fw-600 {{ $neg ? 'inv-neg' : 'inv-pos' }}">{{ $fmt::qty($m->quantity, true) }} <span class="text-muted fw-500">{{ $m->item?->unit_of_measure }}</span></td>
                            <td class="text-right mono">{{ $m->unit_cost !== null ? $fmt::cost($m->unit_cost) : '—' }}</td>
                            @if ($multiBranch)<td>{{ $m->branch?->name ?? '—' }}</td>@endif
                            <td>
                                <div>{{ $m->reference ?: ($m->reason ?: '—') }}</div>
                                @if ($m->reference && $m->reason)<div class="text-muted text-xs">{{ $m->reason }}</div>@endif
                                @if ($m->lot)<div class="text-muted text-xs">Lote {{ $m->lot->lot_code }}</div>@endif
                            </td>
                            <td class="text-muted">{{ $m->actor?->full_name ?? '—' }}</td>
                            <td class="actions">
                                @if ($m->reversal_exists)
                                    <x-ui.badge variant="neutral">Revertido</x-ui.badge>
                                @elseif ($reversible)
                                    <x-ui.button variant="ghost" size="sm" icon="refresh"
                                                 data-modal-open="modal-revertir"
                                                 data-reverse-action="{{ route('inventario.movimientos.reversion', $m) }}"
                                                 data-reverse-label="{{ $m->movement_type->label() }} · {{ $m->item?->name }}">Revertir</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $multiBranch ? 9 : 8 }}">
                            <x-ui.empty-state icon="clock" title="Sin transacciones" text="{{ $hasFilters ? 'Ningún movimiento coincide con los filtros.' : 'Todavía no hay movimientos de inventario.' }}" />
                        </td></tr>
                    @endforelse
                </x-ui.table>
                <div class="table-foot">
                    <span>{{ $movements->total() }} {{ $movements->total() === 1 ? 'movimiento' : 'movimientos' }}</span>
                    <div class="pagination-wrap">{{ $movements->links() }}</div>
                </div>
            @endif
        </x-ui.card>
    </div>

    @if ($can['create'])
        @include('inventario.partials._modals', ['preItem' => null])
    @endif
    @if ($isMovs && $can['edit'])
        @include('inventario.partials._reverse-modal')
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/inventario.js') }}"></script>
@endpush

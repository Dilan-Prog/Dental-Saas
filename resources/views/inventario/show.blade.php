@extends('layouts.app')

@section('title', $item->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/inventario.css') }}">
@endpush

@php
    $fmt = new \App\Services\Inventario\InventarioFormat();
    $inactive = $item->status === \App\Enums\RecordStatus::Inactive;
    $multiBranch = $branches->count() > 1;
    $branchOptions = ['all' => 'Todas las sucursales'] + $branches->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    $missingInitial = $branches->filter(fn ($b) => ! in_array($b->id, $initialByBranch, true));
    $totalOut = $rows->contains('out', true);
    $today = now()->startOfDay();
    $limit = now()->addDays(\App\Services\Inventario\InventarioReport::EXPIRY_DAYS)->startOfDay();
    $typeBadge = ['inventario_inicial' => 'neutral', 'entrada' => 'success', 'salida' => 'warning', 'ajuste' => 'info', 'reversion' => 'danger'];
    $supplierOptions = $suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name])->all();
    // Si el proveedor actual esta inactivo tambien debe poder verse en el selector de edicion.
    if ($item->supplier && ! isset($supplierOptions[$item->supplier->id])) {
        $supplierOptions[$item->supplier->id] = $item->supplier->name . ' (inactivo)';
    }
@endphp

@section('breadcrumbs')
    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('inventario.index') }}" class="crumb-extra">Inventario</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current truncate">{{ $item->name }}</span>
@endsection

@section('content')
    <x-ui.page-header :title="$item->name"
                      :subtitle="$item->sku . ($item->category ? ' · ' . $item->category : '') . ' · ' . $item->unit_of_measure . ' · ' . ($item->supplier?->name ?? 'Sin proveedor')">
        <x-slot name="actions">
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('inventario.index')">Inventario</x-ui.button>
            @if ($can['create'] && ! $inactive)
                <x-ui.button variant="secondary" icon="download" data-modal-open="modal-entrada">Entrada</x-ui.button>
                <x-ui.button variant="secondary" icon="upload" data-modal-open="modal-salida">Salida</x-ui.button>
                <x-ui.button variant="secondary" icon="edit" data-modal-open="modal-ajuste">Ajuste</x-ui.button>
                @if ($missingInitial->isNotEmpty())
                    <x-ui.button variant="secondary" data-modal-open="modal-inicial">Inventario inicial</x-ui.button>
                @endif
            @endif
            @if ($can['edit'])
                <x-ui.button icon="edit" data-modal-open="modal-editar-insumo">Editar</x-ui.button>
            @endif
            @if ($can['toggle'])
                <x-ui.confirm-form :action="route('inventario.items.toggle', $item)" method="PATCH"
                                   :message="$inactive ? '¿Activar este insumo?' : '¿Desactivar este insumo? Ya no aparecerá en los movimientos nuevos.'"
                                   :label="$inactive ? 'Activar' : 'Desactivar'" :variant="$inactive ? 'primary' : 'danger'" size="md" />
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="stack">
        @if ($inactive)
            <x-ui.alert variant="info" title="Insumo inactivo">No se pueden registrar movimientos nuevos. El historial se conserva.</x-ui.alert>
        @endif

        <div class="grid grid-4 inv-kpis">
            <x-ui.kpi label="Existencia total" :value="$fmt::qty($totals['qty']) . ' ' . $item->unit_of_measure" />
            <x-ui.kpi label="Valor en inventario" :value="$fmt::money($totals['value'])" delta="Costo promedio ponderado" />
            <x-ui.kpi label="Estado" :value="$totalOut ? 'Agotado' : ($totals['low'] ? 'Bajo mínimo' : 'Normal')" :trend="($totalOut || $totals['low']) ? 'down' : 'up'" />
            <x-ui.kpi label="Lotes con existencia" :value="$lots->where('quantity_on_hand', '>', 0)->count()" />
        </div>

        <x-ui.card flush title="Existencias por sucursal" subtitle="Mínimo y ubicación se configuran por sucursal">
            <x-ui.table>
                <x-slot name="head">
                    <tr><th>Sucursal</th><th class="text-right">Existencia</th><th class="text-right">Mínimo</th><th class="text-right">Costo prom.</th><th class="text-right">Valor</th><th>Ubicación</th><th class="actions"></th></tr>
                </x-slot>
                @foreach ($rows as $r)
                    <tr class="inv-row {{ $r->out ? 'inv-row--out' : ($r->low ? 'inv-row--low' : '') }}">
                        <td class="fw-600">{{ $r->branch->name }} @if ($r->branch->is_main)<x-ui.badge variant="primary">Principal</x-ui.badge>@endif</td>
                        <td class="text-right mono">
                            <span class="inv-stock {{ $r->out ? 'inv-stock--out' : ($r->low ? 'inv-stock--low' : '') }}">{{ $fmt::qty($r->qty) }} {{ $item->unit_of_measure }}@if ($r->low)<x-ui.icon name="bell" size="sm" />@endif</span>
                        </td>
                        <td class="text-right mono text-muted">{{ $fmt::qty($r->min) }}</td>
                        <td class="text-right mono">{{ $fmt::cost($r->cost) }}</td>
                        <td class="text-right mono fw-600">{{ $fmt::money($r->value) }}</td>
                        <td>{{ $r->location ?: '—' }}</td>
                        <td class="actions">
                            @if ($can['edit'])
                                <x-ui.button variant="ghost" size="sm" icon="edit" icon-only aria-label="Configurar mínimo y ubicación"
                                             data-modal-open="modal-stock"
                                             data-stock-branch="{{ $r->branch->id }}" data-stock-branch-name="{{ $r->branch->name }}"
                                             data-stock-min="{{ $fmt::qty($r->min) }}" data-stock-location="{{ $r->location }}" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        @if ($lots->isNotEmpty())
            <x-ui.card flush title="Lotes y caducidades">
                <x-ui.table>
                    <x-slot name="head">
                        <tr><th>Lote</th>@if ($multiBranch)<th>Sucursal</th>@endif<th>Caducidad</th><th class="text-right">Existencia</th></tr>
                    </x-slot>
                    @foreach ($lots as $lot)
                        @php
                            $exp = $lot->expires_on;
                            $has = (float) $lot->quantity_on_hand > 0;
                            $state = ! $exp || ! $has ? null : ($exp->lt($today) ? 'expired' : ($exp->lte($limit) ? 'soon' : null));
                        @endphp
                        <tr class="{{ ! $has ? 'inv-row--inactive' : '' }}">
                            <td class="mono fw-600">{{ $lot->lot_code }}</td>
                            @if ($multiBranch)<td>{{ $branches->firstWhere('id', $lot->branch_id)?->name ?? '—' }}</td>@endif
                            <td>
                                {{ $exp ? $exp->format('d/m/Y') : 'Sin caducidad' }}
                                @if ($state === 'expired')<x-ui.badge variant="danger">Vencido</x-ui.badge>@elseif ($state === 'soon')<x-ui.badge variant="warning">Por caducar</x-ui.badge>@endif
                            </td>
                            <td class="text-right mono">{{ $fmt::qty($lot->quantity_on_hand) }} {{ $item->unit_of_measure }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        <x-ui.card flush title="Kardex" subtitle="Movimientos con saldo corrido por sucursal">
            @if ($multiBranch)
                <form method="GET" action="{{ route('inventario.items.show', $item) }}" class="table-toolbar" data-autosubmit>
                    <x-ui.select name="sucursal" :options="$branchOptions" :selected="$branchFilter" aria-label="Sucursal" />
                </form>
            @endif
            <x-ui.table class="inv-table">
                <x-slot name="head">
                    <tr>
                        <th>Fecha</th><th>Tipo</th>@if ($multiBranch)<th>Sucursal</th>@endif
                        <th class="text-right">Entrada</th><th class="text-right">Salida</th><th class="text-right">Saldo</th>
                        <th class="text-right">Costo uni.</th><th>Detalle</th><th>Usuario</th><th class="actions"></th>
                    </tr>
                </x-slot>
                @forelse ($kardex as $k)
                    @php
                        $q = (float) $k->quantity;
                        $reversible = $can['edit'] && ! in_array($k->type->value, ['reversion', 'inventario_inicial'], true) && ! $k->is_reversed;
                    @endphp
                    <tr>
                        <td class="nowrap">{{ $k->occurred->format('d/m/Y H:i') }}</td>
                        <td><x-ui.badge :variant="$typeBadge[$k->type->value] ?? 'neutral'">{{ $k->type->label() }}</x-ui.badge></td>
                        @if ($multiBranch)<td>{{ $k->branch_name }}</td>@endif
                        <td class="text-right mono inv-pos">{{ $q > 0 ? $fmt::qty($q) : '' }}</td>
                        <td class="text-right mono inv-neg">{{ $q < 0 ? $fmt::qty(abs($q)) : '' }}</td>
                        <td class="text-right mono fw-600">{{ $fmt::qty($k->balance) }}</td>
                        <td class="text-right mono">{{ $k->unit_cost !== null ? $fmt::cost($k->unit_cost) : '—' }}</td>
                        <td>
                            <div>{{ $k->reference ?: ($k->reason ?: '—') }}</div>
                            @if ($k->reference && $k->reason)<div class="text-muted text-xs">{{ $k->reason }}</div>@endif
                            @if ($k->lot_code)<div class="text-muted text-xs">Lote {{ $k->lot_code }}</div>@endif
                        </td>
                        <td class="text-muted">{{ $k->actor_name }}</td>
                        <td class="actions">
                            @if ($k->is_reversed)
                                <x-ui.badge variant="neutral">Revertido</x-ui.badge>
                            @elseif ($reversible)
                                <x-ui.button variant="ghost" size="sm" icon="refresh" data-modal-open="modal-revertir"
                                             data-reverse-action="{{ route('inventario.movimientos.reversion', $k->id) }}"
                                             data-reverse-label="{{ $k->type->label() }} · {{ $item->name }}">Revertir</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $multiBranch ? 10 : 9 }}"><x-ui.empty-state icon="clock" title="Sin movimientos" text="Este insumo todavía no tiene movimientos." /></td></tr>
                @endforelse
            </x-ui.table>
            <div class="table-foot">
                <span>{{ $kardex->total() }} {{ $kardex->total() === 1 ? 'movimiento' : 'movimientos' }}</span>
                <div class="pagination-wrap">{{ $kardex->links() }}</div>
            </div>
        </x-ui.card>
    </div>

    {{-- ----------------------- Modales de edicion ----------------------- --}}
    @if ($can['edit'])
        <x-ui.modal id="modal-editar-insumo" title="Editar insumo" subtitle="Datos generales; no modifica existencias" size="lg"
                    :action="route('inventario.items.update', $item)" method="PUT" open-on-error>
            <div class="form-grid">
                <x-ui.field label="Nombre" name="name" bag="insumo_edit" :value="$item->name" required maxlength="200" />
                <x-ui.field label="SKU" name="sku" bag="insumo_edit" :value="$item->sku" required maxlength="60" mask="upper" />
                <x-ui.field label="Categoría" name="category" bag="insumo_edit" :value="$item->category" maxlength="100" list="inv-categories" />
                <x-ui.field label="Proveedor" name="supplier_id" as="select" bag="insumo_edit" :options="$supplierOptions" :selected="$item->supplier_id" placeholder="Sin proveedor" />
                <x-ui.field label="Unidad de medida" name="unit_of_measure" bag="insumo_edit" :value="$item->unit_of_measure" required maxlength="30" list="inv-units" />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar cambios</x-ui.button>
            </x-slot>
        </x-ui.modal>

        <x-ui.modal id="modal-stock" title="Mínimo y ubicación" subtitle="Configuración del insumo en la sucursal"
                    :action="route('inventario.items.stock-settings', $item)" method="PUT" open-on-error>
            <input type="hidden" name="branch_id" value="{{ old('branch_id', $branches->first()?->id) }}" data-stock-branch-input>
            <p class="text-muted text-sm" data-stock-branch-label></p>
            <div class="form-grid">
                <x-ui.field label="Stock mínimo" name="min_quantity" bag="stock" type="number" step="0.0001" min="0" required data-stock-min-input />
                <x-ui.field label="Ubicación" name="location" bag="stock" maxlength="100" placeholder="Ej. Estante A-1" data-stock-location-input />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>

        @include('inventario.partials._reverse-modal')
    @endif

    @if ($can['create'])
        @include('inventario.partials._modals', ['preItem' => $item])
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/inventario.js') }}"></script>
@endpush

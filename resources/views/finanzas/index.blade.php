@extends('layouts.app')

@section('title', 'Finanzas')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/finanzas.css') }}?v={{ @filemtime(public_path('css/modules/finanzas.css')) }}">
@endpush

@php
    use App\Enums\MovementDirection;
    use App\Services\Finanzas\FinanceFormatter as Fmt;
    use App\Support\Money;

    $user = auth()->user();
    $canCreate = $user->hasPermission('finanzas', 'crear');
    $canEdit = $user->hasPermission('finanzas', 'editar');
    $canClose = $user->hasPermission('finanzas', 'cerrar_caja');
    $canExport = $user->hasPermission('finanzas', 'exportar');

    $delta = function (?float $v, bool $upIsGood) {
        if ($v === null) { return [null, null]; }
        $text = ($v >= 0 ? '+' : '−').number_format(abs($v), 1).'% vs. periodo anterior';
        $good = $v === 0.0 ? null : (($v > 0) === $upIsGood);
        return [$text, $good === null ? null : ($good ? 'up' : 'down')];
    };
    [$incomeDelta, $incomeTrend] = $delta($kpis['income_delta'], true);
    [$expenseDelta, $expenseTrend] = $delta($kpis['expense_delta'], false);

    $openCount = $openSessions->count();
    $singleOpen = $openCount === 1 ? $openSessions->first() : null;
    $mainBranchId = optional($branches->firstWhere('is_main', true))->id ?? optional($branches->first())->id;
    $selectableCategories = $categories->reject(fn ($c) => in_array($c->name, $systemCategoryNames, true));
@endphp

@section('content')
    <x-ui.page-header title="Finanzas" :subtitle="'Ingresos, egresos y corte de caja · '.$periodLabel">
        <x-slot name="actions">
            @if ($canExport)
                <x-ui.button variant="ghost" icon="download" :href="route('finanzas.export', request()->query())">Exportar CSV</x-ui.button>
            @endif
            @if ($singleOpen && $canClose)
                <x-ui.button variant="secondary" data-modal-open="corte-{{ $singleOpen->id }}">Corte de caja</x-ui.button>
            @elseif ($openCount > 1)
                <x-ui.button variant="secondary" :href="route('finanzas.cajas.index')">Corte de caja</x-ui.button>
            @elseif ($canCreate)
                <x-ui.button variant="secondary" data-modal-open="abrir-caja">Abrir caja</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" data-modal-open="nueva-transaccion">Nueva transacción</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    @include('finanzas.partials.tabs', ['active' => 'resumen'])

    {{-- Filtros --}}
    <form method="GET" action="{{ route('finanzas.index') }}" class="card fin-filters" data-fin-filters>
        {{-- Envio por defecto (Enter en un campo): aplica las fechas/filtros manuales, no el preset rapido. --}}
        <button type="submit" name="preset" value="custom" hidden tabindex="-1" aria-hidden="true"></button>
        <div class="fin-filters__row">
            <div class="segmented" role="group" aria-label="Periodo">
                @foreach ($presets as $key => $label)
                    @continue($key === 'custom')
                    <button type="submit" name="preset" value="{{ $key }}" class="{{ $filters['preset'] === $key ? 'is-active' : '' }}">{{ $label }}</button>
                @endforeach
            </div>
            <x-ui.field label="Desde" name="from" type="date" :value="$filters['from']->format('Y-m-d')" class="fin-filters__date" />
            <x-ui.field label="Hasta" name="to" type="date" :value="$filters['to']->format('Y-m-d')" class="fin-filters__date" />
            <x-ui.field label="Tipo" name="direction" as="select" :options="$directions" :selected="$filters['direction']" placeholder="Todos" />
            <x-ui.field label="Categoría" name="category_id" as="select" :options="$categories->pluck('name', 'id')->all()" :selected="$filters['category_id']" placeholder="Todas" />
            <x-ui.field label="Método" name="method" as="select" :options="$methods" :selected="$filters['method']" placeholder="Todos" />
            @if ($branches->count() > 1)
                <x-ui.field label="Sucursal" name="branch_id" as="select" :options="$branches->pluck('name', 'id')->all()" :selected="$filters['branch_id']" placeholder="Todas" />
            @endif
            <x-ui.field label="Buscar concepto" name="q" icon="search" :value="$filters['q']" placeholder="Ej. nómina, renta…" />
            <div class="fin-filters__actions">
                <x-ui.button type="submit" name="preset" value="custom">Aplicar</x-ui.button>
                @if (request()->query())
                    <x-ui.button variant="ghost" :href="route('finanzas.index')">Limpiar</x-ui.button>
                @endif
            </div>
        </div>
    </form>

    {{-- KPI --}}
    <div class="grid grid-4 fin-kpis">
        <x-ui.kpi label="Ingresos del periodo" :value="Money::format($kpis['income'])" :delta="$incomeDelta ?? 'Sin periodo previo para comparar'" :trend="$incomeTrend" />
        <x-ui.kpi label="Egresos del periodo" :value="Money::format($kpis['expense'])" :delta="$expenseDelta ?? 'Sin periodo previo para comparar'" :trend="$expenseTrend" />
        <x-ui.kpi label="Utilidad operativa" :value="Money::format($kpis['profit'])"
                  :delta="$kpis['margin'] === null ? 'Sin ingresos en el periodo' : number_format($kpis['margin'], 0).'% de margen'"
                  :trend="Money::cmp($kpis['profit'], '0') >= 0 ? 'up' : 'down'" />
        <x-ui.kpi label="En caja hoy" :value="Money::format($cashOnHand['amount'])"
                  :delta="$openCount === 0 ? 'sin turno abierto' : ($openCount === 1 ? 'turno abierto' : $openCount.' turnos abiertos')" />
    </div>

    <div class="grid grid-2 fin-charts">
        {{-- Gastos por categoria --}}
        <x-ui.card title="Gastos por categoría" :subtitle="Money::cmp($kpis['expense'], '0') > 0 ? 'Total '.Money::format($kpis['expense']) : null">
            @if ($expenseByCategory->isEmpty())
                <x-ui.empty-state icon="activity" title="Sin egresos en el periodo" text="Registra una transacción de tipo egreso para ver su distribución." />
            @else
                <div class="fin-stack" role="img" aria-label="Distribución de gastos por categoría">
                    @foreach ($expenseByCategory as $row)
                        <span style="width:{{ $row['percent'] }}%;background:{{ $row['color'] }}" title="{{ $row['name'] }}: {{ Money::format($row['total']) }} ({{ $row['percent'] }}%)"></span>
                    @endforeach
                </div>
                <ul class="fin-legend">
                    @foreach ($expenseByCategory as $row)
                        <li>
                            <span class="fin-legend__dot" style="background:{{ $row['color'] }}"></span>
                            <span class="fin-legend__name">{{ $row['name'] }}</span>
                            <span class="fin-legend__amount text-muted mono">{{ Money::format($row['total']) }}</span>
                            <span class="fin-legend__pct mono">{{ rtrim(rtrim(number_format($row['percent'], 1), '0'), '.') }}%</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Ingresos vs egresos (ultimos 6 meses) --}}
        <x-ui.card title="Ingresos vs egresos" subtitle="Últimos 6 meses">
            <div class="fin-chart" role="img" aria-label="Ingresos contra egresos por mes">
                @foreach ($series as $point)
                    <div class="fin-chart__group">
                        <div class="fin-chart__bars">
                            <span class="fin-chart__bar fin-chart__bar--income" style="height:{{ max($point['income_pct'], Money::cmp($point['income'], '0') > 0 ? 2 : 0) }}%"
                                  title="{{ $point['label'] }} · Ingresos {{ Money::format($point['income']) }}"></span>
                            <span class="fin-chart__bar fin-chart__bar--expense" style="height:{{ max($point['expense_pct'], Money::cmp($point['expense'], '0') > 0 ? 2 : 0) }}%"
                                  title="{{ $point['label'] }} · Egresos {{ Money::format($point['expense']) }}"></span>
                        </div>
                        <div class="fin-chart__label">{{ $point['label'] }}</div>
                    </div>
                @endforeach
            </div>
            <div class="fin-chart__legend text-sm text-muted">
                <span><i class="fin-legend__dot fin-chart__bar--income"></i> Ingresos</span>
                <span><i class="fin-legend__dot fin-chart__bar--expense"></i> Egresos</span>
            </div>
        </x-ui.card>
    </div>

    {{-- Transacciones --}}
    <x-ui.card title="Transacciones" :subtitle="$movements->total().' movimientos en el periodo'" flush class="fin-transactions">
        <x-ui.table>
            <x-slot name="head">
                <tr>
                    <th>Fecha</th>
                    <th>Concepto</th>
                    <th>Categoría</th>
                    <th>Método</th>
                    <th class="text-right">Monto</th>
                    <th class="actions"></th>
                </tr>
            </x-slot>
            @forelse ($movements as $m)
                @php
                    $income = $m->direction === MovementDirection::Ingreso;
                    $isReversed = $m->reversal !== null;
                    $isReversal = $m->reversed_of_id !== null;
                    $fromPayment = $m->payment_id !== null;
                @endphp
                <tr class="{{ $isReversed || $isReversal ? 'fin-row--muted' : '' }}">
                    <td class="mono">{{ Fmt::date($m->occurred_on) }}</td>
                    <td>
                        <div class="fin-concept {{ $isReversed ? 'is-struck' : '' }}">{{ $m->concept }}</div>
                        <div class="fin-tags">
                            @if ($fromPayment)<x-ui.badge variant="info">Pago de paciente</x-ui.badge>@endif
                            @if ($isReversed)<x-ui.badge variant="warning">Revertido</x-ui.badge>@endif
                            @if ($isReversal)<x-ui.badge variant="neutral">Reversión</x-ui.badge>@endif
                            @if ($m->cashSession)<span class="text-xs text-muted">Caja: {{ $m->cashSession->cashRegister?->name }}</span>@endif
                        </div>
                    </td>
                    <td><x-ui.badge>{{ $m->category?->name }}</x-ui.badge></td>
                    <td>{{ $m->method->label() }}</td>
                    <td class="text-right mono fin-amount {{ $income ? 'fin-amount--in' : 'fin-amount--out' }}">{{ Fmt::signed($m->amount, $income) }}</td>
                    <td class="actions">
                        @if ($m->receipt_storage_key || ($canEdit && $m->isManual() && ! $isReversed))
                            <x-ui.dropdown>
                                <x-slot name="trigger">
                                    <x-ui.button variant="ghost" size="sm" icon="more-horizontal" icon-only aria-label="Acciones del movimiento" />
                                </x-slot>
                                @if ($m->receipt_storage_key)
                                    <a class="dropdown-item" href="{{ route('finanzas.movimientos.receipt', $m) }}" target="_blank" rel="noopener"><x-ui.icon name="paperclip" /> Ver comprobante</a>
                                @endif
                                @if ($canEdit && $m->isManual() && ! $isReversed)
                                    <button type="button" class="dropdown-item dropdown-item--danger" data-reverse-movement
                                            data-action="{{ route('finanzas.movimientos.reverse', $m) }}"
                                            data-concept="{{ $m->concept }}" data-amount="{{ Money::format($m->amount) }}">
                                        <x-ui.icon name="refresh" /> Revertir
                                    </button>
                                @endif
                            </x-ui.dropdown>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-ui.empty-state icon="receipt" title="Sin transacciones" text="No hay movimientos con los filtros seleccionados en este periodo." />
                </td></tr>
            @endforelse
        </x-ui.table>
        @if ($movements->hasPages())
            <div class="card__footer">{{ $movements->links() }}</div>
        @endif
    </x-ui.card>

    @if ($canCreate)
        @include('finanzas.partials.modal-movement')
        @unless ($openCount)
            @include('finanzas.partials.modal-open')
        @endunless
    @endif
    @if ($canClose)
        @foreach ($openSessions as $s)
            @include('finanzas.partials.modal-close', ['s' => $s])
        @endforeach
    @endif
    @if ($canEdit)
        @include('finanzas.partials.modal-reverse')
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/finanzas.js') }}?v={{ @filemtime(public_path('js/modules/finanzas.js')) }}"></script>
@endpush

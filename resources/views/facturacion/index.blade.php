<x-app-layout title="Facturación" :breadcrumbs="['Facturación']">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/modules/facturacion.css') }}?v={{ @filemtime(public_path('css/modules/facturacion.css')) }}">
    @endpush

    @php
        $u = auth()->user();
        $canCreate = $u->hasPermission('facturacion', 'crear');
        $canPay = $u->hasPermission('facturacion', 'registrar_pagos');
        $canExport = $u->hasPermission('facturacion', 'exportar');
        $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    @endphp

    <x-ui.page-header title="Facturación" subtitle="CFDI 4.0 · Facturas y cobranza">
        <x-slot name="actions">
            @if ($canExport)
                <x-ui.button variant="secondary" icon="download" :href="route('facturacion.export', request()->query())">Exportar</x-ui.button>
            @endif
            @if ($canPay)
                <x-ui.button variant="secondary" icon="credit-card" :href="route('pagos.create')">Registrar pago</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('facturacion.create')">Nueva factura</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-3 mb-2">
        <x-ui.kpi label="Facturado del mes" :value="\App\Support\Money::format($kpis['invoiced'])" />
        <x-ui.kpi label="Por cobrar" :value="\App\Support\Money::format($kpis['receivable'])"
                  :delta="$kpis['receivable_count'] . ' ' . ($kpis['receivable_count'] === 1 ? 'factura pendiente' : 'facturas pendientes')" />
        <x-ui.kpi label="Cobrado del mes" :value="\App\Support\Money::format($kpis['collected'])" />
    </div>

    <x-ui.card flush>
        <form method="GET" action="{{ route('facturacion.index') }}" class="fact-filters" data-no-lock>
            <div class="field field--grow">
                <label class="field__label" for="q">Buscar</label>
                <div class="input-wrap"><x-ui.icon name="search" size="sm" />
                    <input class="input" id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Folio, paciente o UUID fiscal">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="fiscal_status">Estado fiscal</label>
                <select class="select" id="fiscal_status" name="fiscal_status">
                    <option value="">Todos</option>
                    @foreach ($fiscalOptions as $v => $l)<option value="{{ $v }}" @selected(($filters['fiscal_status'] ?? '') === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="collection_status">Cobranza</label>
                <select class="select" id="collection_status" name="collection_status">
                    <option value="">Todas</option>
                    @foreach ($collectionOptions as $v => $l)<option value="{{ $v }}" @selected(($filters['collection_status'] ?? '') === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="type">Tipo</label>
                <select class="select" id="type" name="type">
                    <option value="">Todos</option>
                    <option value="I" @selected(($filters['type'] ?? '') === 'I')>Factura (ingreso)</option>
                    <option value="P" @selected(($filters['type'] ?? '') === 'P')>Complemento de pago</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="from">Desde</label>
                <input class="input @error('from') has-error @enderror" id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="field">
                <label class="field__label" for="to">Hasta</label>
                <input class="input @error('to') has-error @enderror" id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            @if ($patientFilter)
                <input type="hidden" name="patient" value="{{ $patientFilter->id }}">
            @endif
            <div class="fact-filters__actions">
                <x-ui.button type="submit" variant="secondary" icon="filter">Filtrar</x-ui.button>
                @if ($hasFilters)<x-ui.button variant="ghost" :href="route('facturacion.index')">Limpiar</x-ui.button>@endif
            </div>
        </form>
        @if ($patientFilter)
            <div style="padding: 10px 20px; border-bottom: 1px solid var(--border);">
                <span class="fact-patient-chip">Paciente: {{ $patientFilter->full_name }}
                    <a href="{{ route('facturacion.index', collect($filters)->except('patient')->all()) }}" aria-label="Quitar filtro">×</a></span>
            </div>
        @endif

        <x-ui.table clickable>
            <x-slot name="head">
                <tr>
                    <th>Folio</th><th>Receptor</th><th>Fecha</th>
                    <th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th><th class="actions"></th>
                </tr>
            </x-slot>
            @forelse ($invoices as $invoice)
                @php
                    $applied = $invoice->applied_sum ?? 0;
                    $balance = \App\Support\Money::sub($invoice->total, $applied);
                    $isOpen = in_array($invoice->fiscal_status->value, ['pendiente_timbrado', 'timbrada', 'cancelacion_solicitada'], true);
                @endphp
                <tr onclick="if(!event.target.closest('a,button'))location.href='{{ route('facturacion.show', $invoice) }}'">
                    <td class="nowrap"><a class="fact-folio" href="{{ route('facturacion.show', $invoice) }}">{{ $invoice->internal_folio }}</a></td>
                    <td>
                        {{ $invoice->patient?->full_name ?? '—' }}
                        @if (! empty($invoice->receiver_snapshot['rfc']))<span class="fact-sub mono">{{ $invoice->receiver_snapshot['rfc'] }}</span>@endif
                    </td>
                    <td class="nowrap">{{ $invoice->created_at->translatedFormat('j M Y') }}</td>
                    <td class="text-right mono fw-500">{{ \App\Support\Money::format($invoice->total) }}</td>
                    <td class="text-right mono">
                        @if ($invoice->fiscal_status->value === 'borrador') <span class="text-subtle">—</span>
                        @elseif ($invoice->fiscal_status->value === 'cancelada') $0
                        @else {{ \App\Support\Money::format($balance) }}
                        @endif
                    </td>
                    <td>@include('facturacion._estado', ['invoice' => $invoice])</td>
                    <td class="actions"><a class="fact-link" href="{{ route('facturacion.show', $invoice) }}">Ver →</a></td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-ui.empty-state icon="receipt" :title="$hasFilters ? 'Sin resultados' : 'Aún no hay facturas'"
                        :text="$hasFilters ? 'Prueba con otros filtros.' : 'Crea tu primera factura para comenzar a cobrar.'" />
                </td></tr>
            @endforelse
        </x-ui.table>

        @if ($invoices->hasPages())
            <div class="table-foot">{{ $invoices->links() }}</div>
        @endif
    </x-ui.card>
</x-app-layout>

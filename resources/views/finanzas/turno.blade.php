@extends('layouts.app')

@section('title', 'Turno de caja')

@section('breadcrumbs')
    <a href="{{ route('dashboard') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('finanzas.index') }}" class="crumb-extra">Finanzas</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('finanzas.cajas.index') }}" class="crumb-extra">Cajas y turnos</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current">Turno</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/finanzas.css') }}?v={{ @filemtime(public_path('css/modules/finanzas.css')) }}">
@endpush

@php
    use App\Enums\CashSessionStatus;
    use App\Enums\MovementDirection;
    use App\Services\Finanzas\FinanceFormatter as Fmt;
    use App\Support\Money;

    $user = auth()->user();
    $isOpen = $session->status === CashSessionStatus::Abierta;
    $canClose = $user->hasPermission('finanzas', 'cerrar_caja');
@endphp

@section('content')
    <x-ui.page-header :title="($session->cashRegister?->name ?? 'Caja').' · turno'"
                      :subtitle="Fmt::dateTime($session->opened_at).' · '.($session->cashRegister?->branch?->name ?? '')">
        <x-slot name="actions">
            <x-ui.button variant="secondary" icon="arrow-left" :href="route('finanzas.cajas.index')">Volver</x-ui.button>
            @if ($isOpen && $canClose)
                <x-ui.button data-modal-open="corte-{{ $session->id }}">Corte de caja</x-ui.button>
            @endif
            @if (! $isOpen && $canClose)
                <x-ui.button variant="secondary" icon="plus" data-modal-open="ajuste-turno">Registrar ajuste</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-4 fin-kpis">
        <x-ui.kpi label="Monto inicial" :value="Money::format($session->opening_amount)" :delta="'Abrió '.($session->opener?->full_name ?? '—')" />
        <x-ui.kpi label="Esperado en caja" :value="Money::format($expected)" :delta="$isOpen ? 'Se actualiza con cada movimiento' : (Money::cmp($adjustmentsTotal, '0') !== 0 ? 'Más ajustes: '.Money::format($adjustmentsTotal) : 'Fijado al cierre')" />
        <x-ui.kpi label="Efectivo contado" :value="$isOpen ? '—' : Money::format($session->counted_cash)" :delta="$isOpen ? 'Pendiente de corte' : 'Cerró '.($session->closer?->full_name ?? '—')" />
        <x-ui.kpi label="Diferencia final" :value="$adjustedDifference === null ? '—' : Money::format($adjustedDifference)"
                  :delta="$isOpen ? 'Turno abierto' : (Money::cmp($session->difference, '0') === 0 ? 'Cuadró al cierre' : 'Al cierre: '.Money::format($session->difference))"
                  :trend="$adjustedDifference === null ? null : (Money::cmp($adjustedDifference, '0') === 0 ? 'up' : 'down')" />
    </div>

    @if (! $isOpen && filled($session->notes))
        <x-ui.alert variant="info" title="Notas del corte">{{ $session->notes }}</x-ui.alert>
    @endif

    <x-ui.card title="Movimientos del turno" :subtitle="$movements->count().' movimientos'" flush>
        <x-ui.table>
            <x-slot name="head">
                <tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th>Método</th><th class="text-right">Monto</th></tr>
            </x-slot>
            @forelse ($movements as $m)
                @php $income = $m->direction === MovementDirection::Ingreso; @endphp
                <tr class="{{ $m->reversal || $m->reversed_of_id ? 'fin-row--muted' : '' }}">
                    <td class="mono">{{ Fmt::dateTime($m->created_at) }}</td>
                    <td>{{ $m->concept }}
                        <div class="fin-tags">
                            @if ($m->payment_id)<x-ui.badge variant="info">Pago de paciente</x-ui.badge>@endif
                            @if ($m->reversal)<x-ui.badge variant="warning">Revertido</x-ui.badge>@endif
                            @if ($m->reversed_of_id)<x-ui.badge>Reversión</x-ui.badge>@endif
                        </div>
                    </td>
                    <td><x-ui.badge>{{ $m->category?->name }}</x-ui.badge></td>
                    <td>{{ $m->method->label() }}</td>
                    <td class="text-right mono fin-amount {{ $income ? 'fin-amount--in' : 'fin-amount--out' }}">{{ Fmt::signed($m->amount, $income) }}</td>
                </tr>
            @empty
                <tr><td colspan="5"><x-ui.empty-state icon="receipt" title="Sin movimientos en este turno" text="Aquí aparecerán los cobros y pagos en efectivo del turno." /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Ajustes del turno" subtitle="Correcciones posteriores al cierre (el turno cerrado no se edita)" flush>
        <x-ui.table>
            <x-slot name="head"><tr><th>Fecha</th><th>Motivo</th><th>Registró</th><th class="text-right">Monto</th></tr></x-slot>
            @forelse ($session->adjustments->sortBy('created_at') as $a)
                <tr>
                    <td class="mono">{{ Fmt::dateTime($a->created_at) }}</td>
                    <td>{{ $a->reason }}</td>
                    <td>{{ $a->creator?->full_name }}</td>
                    <td class="text-right mono fin-amount {{ Money::cmp($a->amount, '0') >= 0 ? 'fin-amount--in' : 'fin-amount--out' }}">{{ Fmt::signed(ltrim((string) $a->amount, '-'), Money::cmp($a->amount, '0') >= 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="4"><x-ui.empty-state icon="clock" title="Sin ajustes" /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($canClose)
        @if ($isOpen)
            @include('finanzas.partials.modal-close', ['s' => $session])
        @else
            <x-ui.modal id="ajuste-turno" title="Registrar ajuste" subtitle="Un ajuste corrige el esperado del turno cerrado" open-on-error
                        action="{{ route('finanzas.turnos.adjust', $session) }}">
                <div class="stack">
                    <x-ui.field label="Monto del ajuste" name="amount" bag="adjust" required placeholder="-100.00"
                                hint="Negativo si el efectivo esperado debe bajar (p. ej. un gasto no registrado); positivo si debe subir." />
                    <x-ui.field label="Motivo" name="reason" bag="adjust" as="textarea" rows="3" required maxlength="500" placeholder="Explica la corrección" />
                </div>
                <x-slot name="footer">
                    <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                    <x-ui.button type="submit">Guardar ajuste</x-ui.button>
                </x-slot>
            </x-ui.modal>
        @endif
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/finanzas.js') }}?v={{ @filemtime(public_path('js/modules/finanzas.js')) }}"></script>
@endpush

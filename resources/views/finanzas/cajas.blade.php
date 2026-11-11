@extends('layouts.app')

@section('title', 'Cajas y turnos')

@section('breadcrumbs')
    <a href="{{ route('dashboard') }}" class="crumb-extra">Inicio</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <a href="{{ route('finanzas.index') }}" class="crumb-extra">Finanzas</a>
    <x-ui.icon name="chevron-right" class="crumb-extra" />
    <span class="is-current">Cajas y turnos</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/finanzas.css') }}?v={{ @filemtime(public_path('css/modules/finanzas.css')) }}">
@endpush

@php
    use App\Enums\CashSessionStatus;
    use App\Services\Finanzas\FinanceFormatter as Fmt;
    use App\Support\Money;

    $user = auth()->user();
    $canCreate = $user->hasPermission('finanzas', 'crear');
    $canEdit = $user->hasPermission('finanzas', 'editar');
    $canClose = $user->hasPermission('finanzas', 'cerrar_caja');
    $activeRegisters = $registers->filter(fn ($r) => $r->status->value === 'active');
    $editingReg = filled(old('_register_action'));
    $bagNewReg = $editingReg ? 'none_new' : 'register';
    $bagEditReg = $editingReg ? 'register' : 'none_edit';
@endphp

@section('content')
    <x-ui.page-header title="Finanzas" subtitle="Cajas, turnos y cortes de caja">
        <x-slot name="actions">
            @if ($canCreate)
                <x-ui.button variant="secondary" icon="plus" data-modal-open="nueva-caja">Nueva caja</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    @include('finanzas.partials.tabs', ['active' => 'cajas'])

    <div class="grid grid-3 fin-registers">
        @foreach ($registers as $register)
            @php $open = $openByRegister->get($register->id); $inactive = $register->status->value !== 'active'; @endphp
            <x-ui.card class="fin-register {{ $inactive ? 'is-inactive' : '' }}">
                <div class="row row--between">
                    <div>
                        <div class="card__title">{{ $register->name }}</div>
                        <div class="text-sm text-muted">{{ $register->branch?->name }}</div>
                    </div>
                    @if ($open)
                        <x-ui.badge variant="success" dot>Turno abierto</x-ui.badge>
                    @elseif ($inactive)
                        <x-ui.badge variant="neutral">Inactiva</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral" dot>Cerrada</x-ui.badge>
                    @endif
                </div>

                @if ($open)
                    <dl class="fin-dl">
                        <div><dt>Abierta</dt><dd>{{ Fmt::dateTime($open->opened_at) }}</dd></div>
                        <div><dt>Monto inicial</dt><dd class="mono">{{ Money::format($open->opening_amount) }}</dd></div>
                        <div><dt>Esperado en caja</dt><dd class="mono"><strong>{{ Money::format($open->expectedCash()) }}</strong></dd></div>
                    </dl>
                @endif

                <div class="row row--wrap" style="margin-top:16px">
                    @if ($open)
                        <x-ui.button size="sm" variant="secondary" :href="route('finanzas.cajas.show', $open)">Ver turno</x-ui.button>
                        @if ($canClose)
                            <x-ui.button size="sm" data-modal-open="corte-{{ $open->id }}">Corte de caja</x-ui.button>
                        @endif
                    @elseif ($canCreate && ! $inactive)
                        <x-ui.button size="sm" data-open-register="{{ $register->id }}" data-modal-open="abrir-caja">Abrir turno</x-ui.button>
                    @endif
                    @if ($canEdit)
                        <x-ui.button size="sm" variant="ghost" icon="edit" data-edit-register
                                     data-action="{{ route('finanzas.cajas.update', $register) }}"
                                     data-name="{{ $register->name }}" data-status="{{ $register->status->value }}">Editar</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.card title="Historial de turnos" flush>
        <x-ui.table clickable>
            <x-slot name="head">
                <tr>
                    <th>Caja</th><th>Apertura</th><th>Cierre</th>
                    <th class="text-right">Inicial</th><th class="text-right">Esperado</th><th class="text-right">Contado</th>
                    <th class="text-right">Diferencia</th><th>Estado</th><th class="actions"></th>
                </tr>
            </x-slot>
            @forelse ($history as $t)
                @php
                    $closed = $t->status === CashSessionStatus::Cerrada;
                    $adj = $closed ? $cash->adjustedDifference($t) : null;
                @endphp
                <tr data-href="{{ route('finanzas.cajas.show', $t) }}">
                    <td><strong>{{ $t->cashRegister?->name }}</strong><div class="text-xs text-muted">{{ $t->cashRegister?->branch?->name }}</div></td>
                    <td class="mono">{{ Fmt::dateTime($t->opened_at) }}<div class="text-xs text-muted">{{ $t->opener?->full_name }}</div></td>
                    <td class="mono">{{ $closed ? Fmt::dateTime($t->closed_at) : '—' }}<div class="text-xs text-muted">{{ $t->closer?->full_name }}</div></td>
                    <td class="text-right mono">{{ Money::format($t->opening_amount) }}</td>
                    <td class="text-right mono">{{ $closed ? Money::format($t->expected_cash) : '—' }}</td>
                    <td class="text-right mono">{{ $closed ? Money::format($t->counted_cash) : '—' }}</td>
                    <td class="text-right mono">
                        @if ($closed)
                            <span class="{{ Money::cmp($adj, '0') === 0 ? '' : (Money::cmp($adj, '0') > 0 ? 'fin-amount--in' : 'fin-amount--out') }}">{{ Money::format($adj) }}</span>
                            @if ((float) $t->adjustments_total !== 0.0)<div class="text-xs text-muted">con ajustes</div>@endif
                        @else — @endif
                    </td>
                    <td>@if ($closed)<x-ui.badge>Cerrado</x-ui.badge>@else<x-ui.badge variant="success" dot>Abierto</x-ui.badge>@endif</td>
                    <td class="actions"><x-ui.button size="sm" variant="ghost" :href="route('finanzas.cajas.show', $t)">Detalle</x-ui.button></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-ui.empty-state icon="credit-card" title="Sin turnos" text="Abre una caja para iniciar el primer turno." /></td></tr>
            @endforelse
        </x-ui.table>
        @if ($history->hasPages())<div class="card__footer">{{ $history->links() }}</div>@endif
    </x-ui.card>

    {{-- Modales --}}
    @if ($canCreate)
        @include('finanzas.partials.modal-open', ['registers' => $activeRegisters])

        <x-ui.modal id="nueva-caja" title="Nueva caja" subtitle="Punto de cobro de una sucursal" open-on-error action="{{ route('finanzas.cajas.store') }}">
            <div class="stack">
                <x-ui.field label="Nombre" name="name" :bag="$bagNewReg" required maxlength="100" placeholder="Ej. Recepción" />
                <x-ui.field label="Sucursal" name="branch_id" :bag="$bagNewReg" as="select" :options="$branches->pluck('name', 'id')->all()" :selected="optional($branches->first())->id" required />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Crear caja</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @if ($canEdit)
        <x-ui.modal id="editar-caja" title="Editar caja" open-on-error action="{{ old('_register_action', '#') }}" method="PUT" data-fin-register-form>
            <input type="hidden" name="_register_action" value="{{ old('_register_action') }}" data-fin-register-action-input>
            <div class="stack">
                <x-ui.field label="Nombre" name="name" :bag="$bagEditReg" required maxlength="100" data-fin-register-name />
                <x-ui.field label="Estado" name="status" :bag="$bagEditReg" as="select" :options="['active' => 'Activa', 'inactive' => 'Inactiva']" required data-fin-register-status />
            </div>
            <x-slot name="footer">
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar</x-ui.button>
            </x-slot>
        </x-ui.modal>
    @endif

    @if ($canClose)
        @foreach ($openByRegister as $s)
            @include('finanzas.partials.modal-close', ['s' => $s])
        @endforeach
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/finanzas.js') }}?v={{ @filemtime(public_path('js/modules/finanzas.js')) }}"></script>
@endpush

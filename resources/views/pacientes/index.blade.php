@extends('layouts.app')

@section('title', 'Pacientes')

@section('breadcrumbs')
    <a href="{{ route('dashboard') }}">Inicio</a>
    <x-ui.icon name="chevron-right" />
    <span class="is-current">Pacientes</span>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/pacientes.css') }}?v={{ @filemtime(public_path('css/modules/pacientes.css')) }}">
@endpush

@section('content')
@php
    $user = auth()->user();
    $canCreate = $user->hasPermission('pacientes', 'crear');
    $canEdit = $user->hasPermission('pacientes', 'editar');
    $canDelete = $user->hasPermission('pacientes', 'desactivar');
    $canExport = $user->hasPermission('pacientes', 'exportar');
    $hasFilters = $filters['q'] !== '' || $filters['estado'] !== '' || $filters['doctor'] !== '';
    $months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
@endphp

<x-ui.page-header title="Pacientes" subtitle="{{ number_format($total) }} {{ $total === 1 ? 'paciente registrado' : 'pacientes registrados' }}">
    <x-slot name="actions">
        @if ($canExport)
            <x-ui.button variant="secondary" icon="download" :href="route('pacientes.export', request()->query())">Exportar</x-ui.button>
        @endif
        @if ($canCreate)
            <x-ui.button icon="plus" data-modal-open="modal-nuevo-paciente">Nuevo paciente</x-ui.button>
        @endif
    </x-slot>
</x-ui.page-header>

<x-ui.card flush>
    <form method="GET" action="{{ route('pacientes.index') }}" class="table-toolbar pac-filters" data-autosubmit data-no-lock>
        <div class="input-wrap pac-filters__search">
            <x-ui.icon name="search" size="sm" />
            <input type="search" name="q" class="input" value="{{ $filters['q'] }}" placeholder="Buscar por nombre, folio, teléfono o email..." aria-label="Buscar paciente" maxlength="100">
        </div>
        <x-ui.select name="doctor" :options="$doctors" :selected="$filters['doctor']" placeholder="Todos los doctores" aria-label="Filtrar por doctor" />
        <x-ui.select name="estado" :selected="$filters['estado']" placeholder="Estado: todos" aria-label="Filtrar por estado"
                     :options="['active' => 'Activos', 'inactive' => 'Inactivos'] + ($canSeeDeleted ? ['deleted' => 'Dados de baja'] : [])" />
        @if ($hasFilters)
            <x-ui.button variant="ghost" :href="route('pacientes.index')" icon="x">Limpiar</x-ui.button>
        @endif
    </form>

    <x-ui.table clickable>
        <x-slot name="head">
            <tr>
                <th>Paciente</th>
                <th>Teléfono</th>
                <th>Última visita</th>
                <th>Doctor</th>
                <th>Balance</th>
                <th>Estado</th>
                <th class="actions">Acciones</th>
            </tr>
        </x-slot>

        @forelse ($patients as $p)
            @php
                $balance = \App\Services\Pacientes\PatientService::balanceOf($p);
                $owes = \App\Support\Money::cmp($balance, 0) > 0;
                $visit = $p->last_visit ? \Illuminate\Support\Carbon::parse($p->last_visit) : null;
                $doctor = $p->assignedDoctor?->user?->full_name;
            @endphp
            <tr data-href="{{ route('pacientes.show', $p) }}" class="{{ $p->trashed() ? 'is-muted' : '' }}">
                <td>
                    <div class="row">
                        <x-ui.avatar :name="$p->full_name" />
                        <div>
                            <div class="fw-600">{{ $p->full_name }}</div>
                            <div class="text-xs text-subtle mono">{{ $p->folio }}@if ($p->age !== null) · {{ $p->age }} {{ $p->age === 1 ? 'año' : 'años' }}@endif</div>
                        </div>
                    </div>
                </td>
                <td class="mono nowrap">{{ $p->phone ? \App\Services\Pacientes\PatientService::formatPhone($p->phone) : '—' }}</td>
                <td class="nowrap">{{ $visit ? $visit->day.' '.$months[$visit->month - 1].' '.$visit->year : '—' }}</td>
                <td>
                    @if ($doctor)
                        <span class="pac-doctor"><i class="pac-dot" style="background: {{ \App\Services\Pacientes\PatientService::doctorColor($p->assigned_doctor_member_id) }}"></i>{{ $doctor }}</span>
                    @else
                        <span class="text-subtle">Sin asignar</span>
                    @endif
                </td>
                <td class="mono nowrap {{ $owes ? 'pac-owes' : 'text-subtle' }}">{{ \App\Support\Money::format($balance) }}</td>
                <td>
                    @if ($p->anonymized_at)
                        <x-ui.badge dot>Anonimizado</x-ui.badge>
                    @elseif ($p->trashed())
                        <x-ui.badge variant="danger" dot>Baja</x-ui.badge>
                    @else
                        <x-ui.badge :variant="$p->status === \App\Enums\RecordStatus::Active ? 'success' : 'neutral'" dot>{{ $p->status->label() }}</x-ui.badge>
                    @endif
                </td>
                <td class="actions nowrap">
                    <x-ui.button variant="ghost" size="sm" icon="eye" icon-only :href="route('pacientes.show', $p)" aria-label="Ver ficha" title="Ver ficha" />
                    @if ($canEdit && ! $p->trashed() && ! $p->anonymized_at)
                        <x-ui.button variant="ghost" size="sm" icon="edit" icon-only :href="route('pacientes.show', ['patient' => $p, 'editar' => 1])" aria-label="Editar" title="Editar" />
                    @endif
                    @if ($canDelete && ! $p->anonymized_at)
                        @if ($p->trashed())
                            <x-ui.confirm-form :action="route('pacientes.restore', $p)" method="POST" variant="secondary" icon="refresh" icon-only
                                label="Reactivar" title="Reactivar paciente" confirmText="Reactivar"
                                :message="'¿Reactivar a '.$p->full_name.' ('.$p->folio.')?'" />
                        @else
                            <x-ui.confirm-form :action="route('pacientes.destroy', $p)" variant="danger-outline" icon="trash" icon-only
                                label="Dar de baja" title="Dar de baja al paciente" confirmText="Dar de baja"
                                :message="$p->full_name.' ('.$p->folio.') se dará de baja y dejará de aparecer en el listado. Su expediente, citas y documentos se conservan y podrá reactivarse.'" />
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    @if ($hasFilters)
                        <x-ui.empty-state icon="search" title="Sin resultados" text="Ningún paciente coincide con la búsqueda o los filtros.">
                            <x-ui.button variant="secondary" :href="route('pacientes.index')">Limpiar filtros</x-ui.button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="user" title="Aún no hay pacientes" text="Registra al primero para comenzar.">
                            @if ($canCreate)<x-ui.button icon="plus" data-modal-open="modal-nuevo-paciente">Nuevo paciente</x-ui.button>@endif
                        </x-ui.empty-state>
                    @endif
                </td>
            </tr>
        @endforelse
    </x-ui.table>

    <div class="table-foot">
        <span>
            @if ($patients->total() > 0)
                Mostrando {{ $patients->firstItem() }}-{{ $patients->lastItem() }} de {{ $patients->total() }} {{ $patients->total() === 1 ? 'paciente' : 'pacientes' }}
            @else
                0 pacientes
            @endif
        </span>
    </div>
    @if ($patients->hasPages()){{ $patients->links() }}@endif
</x-ui.card>

@if ($canCreate)
    <x-ui.modal id="modal-nuevo-paciente" title="Nuevo paciente" subtitle="Datos personales y de contacto" size="lg"
                :action="route('pacientes.store')" method="POST" novalidate>
        @include('pacientes.partials.patient-fields', [
            'bag' => 'patientNew', 'patient' => null, 'withFiscal' => true, 'withStatus' => false, 'hasGuardian' => false,
            'doctors' => $doctors, 'sexOptions' => $sexOptions, 'regimenes' => $regimenes, 'usosCfdi' => $usosCfdi, 'relationships' => $relationships,
        ])
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/modules/pacientes.js') }}?v={{ @filemtime(public_path('js/modules/pacientes.js')) }}"></script>
    @if ($errors->getBag('patientNew')->any())
        <script>document.addEventListener('DOMContentLoaded', function () { UI.openModal('modal-nuevo-paciente'); });</script>
    @endif
@endpush

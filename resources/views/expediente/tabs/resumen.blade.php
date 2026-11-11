@php use App\Services\Expediente\Fmt; @endphp
<div class="grid grid-4">
    <x-ui.kpi label="Próxima cita"
              :value="$nextAppointment ? Fmt::date($nextAppointment->starts_at, 'j M, H:i') : 'Sin cita'"
              :delta="$nextAppointment ? (($nextAppointment->treatment?->name ?? 'Consulta').' · '.Fmt::doctor($nextAppointment->doctor)) : 'No hay citas programadas'" />
    <x-ui.kpi label="Última visita"
              :value="$lastVisit ? Fmt::date($lastVisit) : 'Sin visitas'"
              :delta="$lastVisit ? Fmt::ago($lastVisit) : null" />
    <x-ui.kpi label="Balance pendiente" :value="Fmt::money($balance)"
              :delta="$pendingInvoices ? $pendingInvoices.' factura(s) por cobrar' : 'Sin adeudos'"
              :trend="(float) $balance > 0 ? 'down' : 'up'" />
    <x-ui.kpi label="Tratamientos activos" :value="$activeTreatments->count()"
              :delta="$activeTreatments->pluck('treatment.name')->filter()->unique()->take(3)->implode(' · ') ?: 'Sin tratamientos activos'" />
</div>

<x-ui.card title="Notas clínicas">
    <x-slot name="actions">
        @if ($canCreate)
            <x-ui.button icon="plus" data-modal-open="nueva-nota">Nueva nota</x-ui.button>
        @endif
    </x-slot>
    <div class="note-list">
        @include('expediente.partials.notes-list', ['notes' => $recentNotes])
    </div>
    @if ($counts['notas'] > $recentNotes->count())
        <div class="mt-1"><a href="{{ route('expediente.show', [$patient->id, 'tab' => 'notas']) }}">Ver todas las notas ({{ $counts['notas'] }})</a></div>
    @endif
</x-ui.card>

@if ($canCreate)
    @include('expediente.partials.modal-nueva-nota')
@endif

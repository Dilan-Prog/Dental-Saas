@extends('layouts.app')

@section('title', 'Cita de '.$appointment->patient->full_name)

@section('breadcrumbs')
    <a href="{{ route('agenda.index') }}" class="crumb-extra">Agenda</a>
    <span class="crumb-extra">/</span>
    <strong>Detalle de cita</strong>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/modules/agenda.css') }}?v={{ @filemtime(public_path('css/modules/agenda.css')) }}">
@endpush

@php
    $can = $payload['can'];
    $a = $appointment;
@endphp

@section('content')
    <div data-appt="{{ json_encode($payload, JSON_UNESCAPED_UNICODE) }}" data-appt-root>
        <x-ui.page-header title="Cita de {{ $a->patient->full_name }}" subtitle="{{ ucfirst($a->starts_at->translatedFormat('l j \d\e F \d\e Y')) }} · {{ $a->starts_at->format('H:i') }} – {{ $a->ends_at->format('H:i') }}">
            <x-slot name="actions">
                <x-ui.button variant="secondary" icon="arrow-left" :href="route('agenda.index', ['view' => 'week', 'date' => $a->starts_at->toDateString()])">Volver a la agenda</x-ui.button>
                @if ($can['cancel'])<button type="button" class="btn btn--danger" data-appt-open="cancel">Cancelar cita</button>@endif
                @if ($can['noshow'])
                    <form method="POST" action="{{ route('citas.noshow', $a) }}">@csrf
                        <button type="submit" class="btn btn--secondary" data-confirm="¿Marcar la cita como «No asistió»?" data-confirm-text="Marcar">No asistió</button>
                    </form>
                @endif
                @if ($can['reschedule'])<button type="button" class="btn btn--secondary" data-appt-open="reschedule">Reagendar</button>@endif
                @if ($can['confirm'])
                    <form method="POST" action="{{ route('citas.confirm', $a) }}">@csrf<button type="submit" class="btn btn--secondary">Confirmar</button></form>
                @endif
                @if ($can['complete'])
                    <form method="POST" action="{{ route('citas.complete', $a) }}">@csrf
                        <button type="submit" class="btn btn--primary" data-confirm="¿Marcar la cita como completada?" data-confirm-text="Completar" data-confirm-variant="primary">Completar</button>
                    </form>
                @endif
                @if ($can['start'])<button type="button" class="btn btn--primary" data-appt-open="start">Iniciar</button>@endif
            </x-slot>
        </x-ui.page-header>
    </div>

    <div class="grid grid-2 ag-show">
        <x-ui.card title="Datos de la cita">
            <dl class="ag-dl">
                <div><dt>Paciente</dt><dd>
                    @if ($patientUrl)<a href="{{ $patientUrl }}">{{ $a->patient->full_name }}</a>@else{{ $a->patient->full_name }}@endif
                    <span class="text-muted">({{ $a->patient->folio }})</span></dd></div>
                <div><dt>Tratamiento</dt><dd>{{ $a->treatment?->name ?? 'Sin tratamiento' }}</dd></div>
                <div><dt>Doctor</dt><dd>{{ $a->doctor?->name }}</dd></div>
                <div><dt>Sucursal</dt><dd>{{ $a->branch?->name }}</dd></div>
                <div><dt>Fecha</dt><dd>{{ $a->starts_at->translatedFormat('j M Y') }}</dd></div>
                <div><dt>Horario</dt><dd>{{ $a->starts_at->format('H:i') }} – {{ $a->ends_at->format('H:i') }} ({{ $a->duration_minutes }} min)</dd></div>
                <div><dt>Estado</dt><dd><x-ui.badge :variant="match($a->status->color()) { 'primary' => 'primary', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'info', default => 'neutral' }" dot>{{ $a->status->label() }}</x-ui.badge></dd></div>
                @if ($a->notes)<div><dt>Notas</dt><dd>{{ $a->notes }}</dd></div>@endif
                <div><dt>Registrada</dt><dd>{{ $a->created_at?->translatedFormat('j M Y H:i') }}@if($a->creator) · {{ $a->creator->full_name }}@endif</dd></div>
            </dl>
            @if ($a->encounter && $recordUrl)
                <div class="ag-show__link"><x-ui.button variant="secondary" icon="clipboard" :href="$recordUrl">Abrir expediente del paciente</x-ui.button></div>
            @endif
        </x-ui.card>

        @if ($editable && $canEdit)
            <x-ui.card title="Editar cita" subtitle="Cambia doctor, tratamiento, duración o notas. Para mover la fecha usa «Reagendar».">
                <form method="POST" action="{{ route('citas.update', $a) }}" class="stack">
                    @csrf @method('PUT')
                    <x-ui.field name="doctor_member_id" label="Doctor" required>
                        <x-ui.select name="doctor_member_id" :selected="$a->doctor_member_id" :options="$doctors->mapWithKeys(fn ($d) => [$d->id => $d->name])->all()" />
                    </x-ui.field>
                    <x-ui.field name="treatment_id" label="Tratamiento" required>
                        <x-ui.select name="treatment_id" :selected="$a->treatment_id" placeholder="Selecciona un tratamiento" :options="$treatments->pluck('name', 'id')->all()" />
                    </x-ui.field>
                    <x-ui.field name="duration_minutes" label="Duración (min)" required>
                        <x-ui.input type="number" name="duration_minutes" :value="$a->duration_minutes" min="{{ config('agenda.min_duration') }}" max="{{ config('agenda.max_duration') }}" step="5" />
                    </x-ui.field>
                    <x-ui.field name="notes" label="Notas">
                        <x-ui.textarea name="notes" rows="3" :value="$a->notes" />
                    </x-ui.field>
                    <div class="form-actions"><x-ui.button type="submit">Guardar cambios</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card title="Historial" class="ag-history">
        <x-ui.table>
            <x-slot name="head">
                <tr><th>Fecha</th><th>Cambio</th><th>Detalle</th><th>Usuario</th></tr>
            </x-slot>
            @forelse ($history as $h)
                <tr>
                    <td class="nowrap">{{ $h->created_at?->translatedFormat('j M Y H:i') }}</td>
                    <td>
                        @if ($h->from_status && $h->from_status !== $h->to_status)
                            {{ $h->from_status->label() }} → <strong>{{ $h->to_status->label() }}</strong>
                        @elseif ($h->old_starts_at)
                            Reprogramada
                        @elseif (! $h->from_status)
                            Cita creada ({{ $h->to_status->label() }})
                        @else
                            Modificada
                        @endif
                    </td>
                    <td>
                        @if ($h->old_starts_at && $h->new_starts_at)
                            {{ $h->old_starts_at->format('d/m/Y H:i') }} → {{ $h->new_starts_at->format('d/m/Y H:i') }}@if($h->reason) · @endif
                        @endif
                        {{ $h->reason }}
                    </td>
                    <td>{{ $h->actor?->full_name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">Sin movimientos.</td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @include('agenda._modals', ['mode' => 'actions'])
@endsection

@push('scripts')
    <script>
        window.AGENDA = {
            urls: { patients: @json(route('agenda.patients')), slots: @json(route('agenda.slots')) },
            config: @json(['step' => config('agenda.step'), 'defaultDuration' => config('agenda.default_duration')]),
            filters: {}, today: @json(now()->toDateString()), preselected: null, openNew: false, createFailed: false,
            reopen: @json((old('_form') && old('_appointment') && $errors->getBag(old('_form'))->any()) ? ['form' => old('_form'), 'payload' => $payload] : null),
        };
    </script>
    <script src="{{ asset('js/modules/agenda.js') }}?v={{ @filemtime(public_path('js/modules/agenda.js')) }}"></script>
@endpush

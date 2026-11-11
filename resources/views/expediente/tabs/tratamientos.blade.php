@php
    use App\Enums\PlannedTreatmentStatus as S;
    use App\Services\Expediente\Fmt;
    $variant = ['planeado' => 'neutral', 'en_progreso' => 'primary', 'realizado' => 'success', 'cancelado' => 'danger'];
    $actionLabels = ['en_progreso' => 'Iniciar', 'realizado' => 'Marcar realizado', 'cancelado' => 'Cancelar', 'planeado' => 'Reactivar'];
@endphp
<x-ui.card title="Tratamientos del paciente" subtitle="Plan clínico: aprobado ≠ realizado ≠ cobrado" flush>
    <x-slot name="actions">
        @if ($canCreate)<x-ui.button icon="plus" data-modal-open="nuevo-tratamiento">Agregar tratamiento</x-ui.button>@endif
    </x-slot>

    <x-ui.table>
        <x-slot name="head">
            <tr><th>Tratamiento</th><th>Pieza</th><th>Estado</th><th>Inicio</th><th>Término</th><th>Origen</th><th class="actions"></th></tr>
        </x-slot>
        @forelse ($planItems as $p)
            <tr>
                <td class="fw-500">{{ $p->treatment?->name ?? '—' }}</td>
                <td>{{ $p->tooth_fdi ?: '—' }}</td>
                <td><x-ui.badge :variant="$variant[$p->status->value]" dot>{{ $p->status->label() }}</x-ui.badge></td>
                <td>{{ Fmt::date($p->started_at) }}</td>
                <td>{{ Fmt::date($p->completed_at) }}</td>
                <td>
                    @if ($p->quoteItem?->quote)
                        @if (Route::has('presupuestos.show'))
                            <a href="{{ route('presupuestos.show', $p->quoteItem->quote->id) }}">{{ $p->quoteItem->quote->folio }}</a>
                        @else
                            {{ $p->quoteItem->quote->folio }}
                        @endif
                    @else
                        <span class="text-muted">Manual</span>
                    @endif
                </td>
                <td class="actions">
                    @if ($canWrite)
                        <div class="row" style="justify-content:flex-end;gap:6px">
                            @foreach ($transitions[$p->status->value] as $to)
                                <form method="POST" action="{{ route('expediente.tratamientos.status', [$patient->id, $p->id]) }}" style="display:inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $to }}">
                                    <x-ui.button type="submit" size="sm" :variant="$to === 'cancelado' ? 'danger-outline' : 'secondary'">{{ $actionLabels[$to] }}</x-ui.button>
                                </form>
                            @endforeach
                            @if ($p->status === S::Planeado && ! $p->quote_item_id && auth()->user()->hasPermission('expediente', 'desactivar'))
                                <x-ui.confirm-form :action="route('expediente.tratamientos.destroy', [$patient->id, $p->id])" message="¿Quitar este tratamiento del plan?" label="Eliminar" icon="trash" icon-only variant="ghost" />
                            @endif
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><x-ui.empty-state icon="clipboard" title="Sin tratamientos" text="Agrega tratamientos manualmente o aprueba un presupuesto para generarlos." /></td></tr>
        @endforelse
    </x-ui.table>
</x-ui.card>

@if ($canCreate)
    <x-ui.modal id="nuevo-tratamiento" title="Agregar tratamiento" :subtitle="$patient->full_name" size="sm" open-on-error
                :action="route('expediente.tratamientos.store', $patient->id)">
        <div class="stack">
            <x-ui.field name="treatment_id" label="Tratamiento" as="select" required placeholder="Selecciona un tratamiento"
                        :options="$catalog->pluck('name', 'id')->all()" />
            <x-ui.field name="tooth_fdi" label="Pieza dental" mask="digits" maxlength="2" placeholder="Ej. 26" />
            <x-ui.field name="status" label="Estado" as="select" required :options="App\Enums\PlannedTreatmentStatus::options()" selected="planeado" />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Agregar</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif

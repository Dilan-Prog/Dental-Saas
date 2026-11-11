@php
    use App\Services\Expediente\Fmt;
    use App\Services\Expediente\NoteService;
@endphp
<div class="grid grid-sidebar">
    <x-ui.card title="Historial de consultas">
        <div class="timeline">
            @foreach ($upcoming as $a)
                <div class="timeline__item">
                    <div class="timeline__title">{{ Fmt::date($a->starts_at) }} · {{ Fmt::doctor($a->doctor) }} <span class="text-muted">(programada)</span></div>
                    <div class="timeline__text">{{ $a->treatment?->name ?? 'Consulta' }}{{ $a->notes ? ' — '.$a->notes : '' }}</div>
                </div>
            @endforeach

            @forelse ($encounters as $e)
                <div class="timeline__item">
                    <div class="timeline__title">
                        {{ Fmt::date($e->occurred_at) }} · {{ Fmt::doctor($e->doctor) }}
                        <span class="text-muted text-sm">· {{ $e->branch?->name }}</span>
                    </div>
                    @forelse ($e->notes as $n)
                        <div class="timeline__text">
                            <a href="{{ route('expediente.notas.show', [$patient->id, $n->id]) }}">{{ NoteService::summary($n, 260) ?: 'Nota sin contenido' }}</a>
                            @if (! $n->isSigned())<x-ui.badge variant="warning">Borrador</x-ui.badge>@endif
                        </div>
                    @empty
                        <div class="timeline__text text-muted">Consulta sin notas registradas.</div>
                    @endforelse
                </div>
            @empty
                @if ($upcoming->isEmpty())
                    <x-ui.empty-state icon="clock" title="Sin consultas registradas" text="Las consultas aparecen al iniciar una cita o registrar una nota." />
                @endif
            @endforelse
        </div>
    </x-ui.card>

    <x-ui.card title="Alergias y antecedentes" subtitle="Datos médicos relevantes del paciente">
        @if ($canWrite)
            <form method="POST" action="{{ route('expediente.historial.update', $patient->id) }}" class="stack">
                @csrf @method('PUT')
                <x-ui.field name="allergies" label="Alergias" as="textarea" rows="3" :value="$record->allergies" maxlength="2000" placeholder="Ej. Penicilina, látex…" />
                <x-ui.field name="medical_history" label="Antecedentes médicos" as="textarea" rows="6" :value="$record->medical_history" maxlength="5000" placeholder="Enfermedades, cirugías, medicamentos actuales…" />
                <div class="form-actions"><x-ui.button type="submit">Guardar</x-ui.button></div>
            </form>
        @else
            <dl class="dl">
                <dt>Alergias</dt><dd>{{ $record->allergies ?: '—' }}</dd>
                <dt>Antecedentes</dt><dd>{!! nl2br(e($record->medical_history ?: '—')) !!}</dd>
            </dl>
        @endif
    </x-ui.card>
</div>

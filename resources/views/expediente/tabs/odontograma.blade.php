@php
    use App\Enums\DentitionType;
    use App\Enums\ToothCondition;
    use App\Services\Expediente\Fmt;
    use App\Services\Expediente\OdontogramService;

    $conditions = ToothCondition::options();
    $dentUrl = fn (DentitionType $d) => route('expediente.show', ['patient' => $patient->id, 'tab' => 'odontograma', 'denticion' => $d->value]);

    // Superficie que cae a la derecha/izquierda del diente según el cuadrante (mesial mira a la línea media).
    $rightSurface = fn (int $t) => in_array(intdiv($t, 10), [1, 4, 5, 8], true) ? 'M' : 'D';
    $leftSurface = fn (int $t) => $rightSurface($t) === 'M' ? 'D' : 'M';
    $innerSurface = fn (string $arch) => $arch === 'upper' ? 'P' : 'L';
    $surfaceNames = ['M' => 'Mesial', 'D' => 'Distal', 'O' => 'Oclusal / incisal', 'V' => 'Vestibular', 'L' => 'Lingual', 'P' => 'Palatina'];
@endphp

<div class="odonto-layout">
    <x-ui.card title="Odontograma" :subtitle="'Dentición '.strtolower($dentition->label()).' · numeración FDI · '.($dentition === DentitionType::Permanente ? '32' : '20').' piezas'">
        <x-slot name="actions">
            <div class="segmented">
                @foreach (DentitionType::cases() as $d)
                    <a href="{{ $dentUrl($d) }}" class="{{ $dentition === $d ? 'is-active' : '' }}">{{ $d->label() }}</a>
                @endforeach
            </div>
        </x-slot>

        <div class="odonto" data-odonto>
            @foreach (['upper' => 'Superior · Maxilar', 'lower' => 'Inferior · Mandíbula'] as $arch => $archLabel)
                <div class="odonto__arch-label">{{ $archLabel }}</div>
                <div class="odonto__row odonto__row--{{ $dentition->value }}">
                    @foreach ($rows[$arch] as $tooth)
                        @php
                            $f = $current->get($tooth);
                            $cond = $f?->condition?->value ?? 'sano';
                            $surf = (array) ($f?->surfaces ?? []);
                            $L = $leftSurface($tooth); $R = $rightSurface($tooth); $I = $innerSurface($arch);
                            $label = $conditions[$cond];
                        @endphp
                        <button type="button" class="tooth tooth--{{ $cond }}" data-tooth="{{ $tooth }}" data-condition="{{ $cond }}"
                                data-surfaces='@json($surf)' data-treatment="{{ $f?->patient_treatment_id }}"
                                data-modal-open="odonto-modal" @disabled(! $canWrite)
                                title="Pieza {{ $tooth }} · {{ $label }}{{ $surf ? ' ('.implode(', ', $surf).')' : '' }}"
                                aria-label="Pieza {{ $tooth }}, {{ $label }}">
                            <span class="tooth__num">{{ $tooth }}</span>
                            <svg class="tooth__svg" viewBox="0 0 36 36" aria-hidden="true">
                                <polygon class="surf surf--V {{ in_array('V', $surf) ? 'is-on' : '' }}" points="2,2 34,2 26,10 10,10"/>
                                <polygon class="surf surf--{{ $I }} {{ in_array($I, $surf) ? 'is-on' : '' }}" points="10,26 26,26 34,34 2,34"/>
                                <polygon class="surf surf--{{ $L }} {{ in_array($L, $surf) ? 'is-on' : '' }}" points="2,2 10,10 10,26 2,34"/>
                                <polygon class="surf surf--{{ $R }} {{ in_array($R, $surf) ? 'is-on' : '' }}" points="34,2 34,34 26,26 26,10"/>
                                <rect class="surf surf--O {{ in_array('O', $surf) ? 'is-on' : '' }}" x="10" y="10" width="16" height="16"/>
                                @if ($cond === 'ausente')<path class="tooth__x" d="M4 4L32 32M32 4L4 32"/>@endif
                            </svg>
                            <span class="tooth__label">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="odonto__legend">
            @foreach ($conditions as $value => $label)
                <span class="legend-item"><i class="legend-dot legend-dot--{{ $value }}"></i>{{ $label }}</span>
            @endforeach
            @if ($canWrite)<span class="text-muted text-xs">Haz clic en una pieza para cambiar su estado.</span>@endif
        </div>
    </x-ui.card>

    <x-ui.card title="Historial de cambios" subtitle="Los hallazgos nunca se borran">
        <div class="timeline">
            @forelse ($toothHistory as $h)
                <div class="timeline__item {{ $h->superseded_at ? 'is-past' : '' }}">
                    <div class="timeline__title">{{ OdontogramService::describe($h) }}</div>
                    <div class="timeline__text text-muted text-xs">
                        {{ Fmt::doctor($h->author) }} · {{ Fmt::dateTime($h->recorded_at) }}
                        @if ($h->superseded_at) · reemplazado {{ Fmt::date($h->superseded_at) }} @else · <strong>vigente</strong> @endif
                    </div>
                </div>
            @empty
                <x-ui.empty-state icon="tooth" title="Sin cambios registrados" text="Todas las piezas se consideran sanas hasta registrar un hallazgo." />
            @endforelse
        </div>
    </x-ui.card>
</div>

@if ($canWrite)
    <x-ui.modal id="odonto-modal" title="Pieza dental" size="sm" open-on-error :action="route('expediente.odontograma.store', $patient->id)">
        <input type="hidden" name="tooth_fdi" value="{{ old('tooth_fdi') }}" data-odonto-tooth>
        <div class="stack">
            <div class="text-sm text-muted" data-odonto-current>Selecciona la condición de la pieza.</div>
            <x-ui.field name="condition" label="Condición" as="select" required :options="$conditions" data-odonto-condition />
            <div class="field" data-odonto-surfaces>
                <label class="field__label">Superficies afectadas</label>
                <div class="row row--wrap" style="gap:14px">
                    @foreach ($surfaceNames as $code => $name)
                        <label class="check"><input type="checkbox" name="surfaces[]" value="{{ $code }}" @checked(in_array($code, (array) old('surfaces', [])))><span>{{ $name }}</span></label>
                    @endforeach
                </div>
                @error('surfaces')<div class="field__error" role="alert">{{ $message }}</div>@enderror
                @error('surfaces.*')<div class="field__error" role="alert">{{ $message }}</div>@enderror
                <div class="field__hint">Sólo aplican a caries, restauración y endodoncia.</div>
            </div>
            @if ($patientTreatments->isNotEmpty())
                <x-ui.field name="patient_treatment_id" label="Tratamiento relacionado" as="select" placeholder="Sin tratamiento"
                            :options="$patientTreatments->mapWithKeys(fn ($t) => [$t->id => $t->treatment?->name.($t->tooth_fdi ? ' · pieza '.$t->tooth_fdi : '')])->all()" data-odonto-treatment />
            @endif
            @if ($encounterOptions->isNotEmpty())
                <x-ui.field name="encounter_id" label="Consulta relacionada" as="select" placeholder="Sin consulta"
                            :options="$encounterOptions->mapWithKeys(fn ($e) => [$e->id => Fmt::dateTime($e->occurred_at)])->all()" />
            @endif
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="submit">Guardar pieza</x-ui.button>
        </x-slot>
    </x-ui.modal>
@endif

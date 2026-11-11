{{-- Formulario: Autorización de tratamiento dental (Figma Documentos 756:17691). --}}
@php
    $fluoride = old('fluoride', [['year' => '', 'details' => ''], ['year' => '', 'details' => '']]);
    $diagnoses = old('diagnoses', [['diagnosis' => '', 'date' => ''], ['diagnosis' => '', 'date' => '']]);
    $activities = old('activities', [['date' => '', 'activity' => '', 'doctor' => ''], ['date' => '', 'activity' => '', 'doctor' => ''], ['date' => '', 'activity' => '', 'doctor' => '']]);
    $doctorNames = $doctors->map(fn ($m) => $m->user?->full_name)->filter()->values();
@endphp

<div class="grid grid-sidebar doc-auth">
    <div class="stack">
        <x-ui.card title="PARODONTOGRAMA / ODONTOGRAMA" class="doc-section">
            <div class="odonto-link">
                <x-ui.icon name="tooth" size="lg" />
                <div class="fw-500">Abrir módulo gráfico interactivo</div>
                <div class="text-muted text-xs">Registra piezas dentales afectadas (derecha/izquierda, sup/inf).</div>
                <x-ui.button variant="secondary" size="sm" :href="route('expediente.show', [$patient->id, 'tab' => 'odontograma'])" target="_blank">Abrir odontograma</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="CONTROL DE APLICACIONES DE FLÚOR" class="doc-section">
            <div class="rows" data-rows="fluoride" data-template="fluoride">
                @foreach ($fluoride as $i => $row)
                    <div class="rows__row" data-row>
                        <x-ui.field :name="'fluoride['.$i.'][year]'" label="Año" type="number" min="1990" max="{{ now()->year }}" placeholder="YYYY" :value="$row['year'] ?? ''" />
                        <x-ui.field :name="'fluoride['.$i.'][details]'" label="Aplicaciones" maxlength="300" placeholder="Detalles de aplicación…" :value="$row['details'] ?? ''" />
                    </div>
                @endforeach
            </div>
            <x-ui.button variant="ghost" size="sm" icon="plus" data-add-row="fluoride">Agregar fila</x-ui.button>
        </x-ui.card>

        <x-ui.card title="DIAGNÓSTICO DENTAL" class="doc-section">
            <div class="rows" data-rows="diagnoses" data-template="diagnoses">
                @foreach ($diagnoses as $i => $row)
                    <div class="rows__row rows__row--diag" data-row>
                        <x-ui.field :name="'diagnoses['.$i.'][diagnosis]'" label="Diagnóstico dental" maxlength="300" placeholder="Escriba el diagnóstico" :value="$row['diagnosis'] ?? ''" />
                        <x-ui.field :name="'diagnoses['.$i.'][date]'" label="Fecha" type="date" max="{{ now()->format('Y-m-d') }}" :value="$row['date'] ?? ''" />
                    </div>
                @endforeach
            </div>
            <x-ui.button variant="ghost" size="sm" icon="plus" data-add-row="diagnoses">Añadir</x-ui.button>
            @error('diagnoses')<div class="field__error" role="alert">{{ $message }}</div>@enderror
        </x-ui.card>
    </div>

    <div class="stack">
        <x-ui.card title="DESARROLLO DE TRATAMIENTO" subtitle="Bitácora de actividades realizadas." class="doc-section">
            <x-slot name="actions"><x-ui.button variant="ghost" size="sm" icon="plus" data-add-row="activities">Agregar fila</x-ui.button></x-slot>
            <div class="rows" data-rows="activities" data-template="activities">
                @foreach ($activities as $i => $row)
                    <div class="rows__row rows__row--3" data-row>
                        <x-ui.field :name="'activities['.$i.'][date]'" label="Fecha" type="date" max="{{ now()->format('Y-m-d') }}" :value="$row['date'] ?? ''" />
                        <x-ui.field :name="'activities['.$i.'][activity]'" label="Actividades realizadas" maxlength="500" placeholder="Descripción de la actividad…" :value="$row['activity'] ?? ''" />
                        <x-ui.field :name="'activities['.$i.'][doctor]'" label="Estomatólogo" maxlength="200" list="doc-doctors" placeholder="Nombre del doctor…" :value="$row['doctor'] ?? ''" />
                    </div>
                @endforeach
            </div>
            <datalist id="doc-doctors">@foreach ($doctorNames as $n)<option value="{{ $n }}">@endforeach</datalist>
        </x-ui.card>

        <x-ui.card title="ACEPTACIÓN DEL PACIENTE" subtitle="Opcional: registra ahora la aceptación (el documento solo NO equivale a aceptación)." class="doc-section">
            <div class="stack" data-acceptance>
                <x-ui.checkbox name="register_acceptance" value="1" label="Registrar la aceptación del paciente / tutor ahora" data-acceptance-toggle />
                <div class="form-grid" data-acceptance-fields>
                    <x-ui.field name="accepted_by_name" label="Quién acepta (paciente o tutor)" maxlength="250" :value="old('accepted_by_name', $patient->full_name)" />
                    <x-ui.field name="acceptance_method" label="Método" as="select" :options="['verbal_registrada' => 'Verbal registrada por el personal', 'firma_digital' => 'Firma digital']" selected="verbal_registrada" />
                </div>
            </div>
        </x-ui.card>
    </div>
</div>

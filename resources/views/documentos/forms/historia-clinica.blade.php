{{-- Formulario: Historia clínica dental (Figma Documentos 756:17691). Variables: $patient, $defaults, $conditions, $complications. --}}
@php $d = fn ($k, $fallback = null) => $defaults[$k] ?? $fallback; @endphp

<x-ui.card title="DATOS DE IDENTIFICACIÓN" class="doc-section">
    <div class="form-grid">
        <x-ui.field class="col-span-2" name="full_name" label="Nombre del paciente" required maxlength="250" :value="$d('full_name')" />
        <x-ui.field name="birth_date" label="Fecha de nacimiento" type="date" max="{{ now()->format('Y-m-d') }}" min="1900-01-02" :value="$d('birth_date')" />
        <x-ui.field name="sex" label="Sexo" as="select" placeholder="Seleccione…" :options="App\Enums\SexType::options()" :selected="$d('sex')" />
        <x-ui.field class="col-span-2" name="address" label="Domicilio de su casa" maxlength="300" />
        <x-ui.field name="phone" label="Teléfono (casa / móvil)" mask="phone" :value="$d('phone')" />
        <x-ui.field name="occupation" label="Ocupación" maxlength="120" />
        <x-ui.field class="col-span-2" name="chief_complaint" label="Motivo principal de la consulta" as="textarea" rows="3" maxlength="1000" />
    </div>
</x-ui.card>

<x-ui.card title="ANTECEDENTES Y HÁBITOS" class="doc-section">
    <div class="form-grid">
        <div class="field">
            <label class="field__label">¿Experiencia desagradable anterior?</label>
            <div class="row">
                <x-ui.radio name="bad_experience" value="si" label="Sí" />
                <x-ui.radio name="bad_experience" value="no" label="No" :checked="true" />
            </div>
            <x-ui.input name="bad_experience_reason" placeholder="¿Por qué?" maxlength="500" />
            @error('bad_experience_reason')<div class="field__error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label class="field__label">Higiene dental</label>
            <div class="row">
                <x-ui.radio name="hygiene" value="cepillo" label="Utiliza cepillo" :checked="true" />
                <x-ui.radio name="hygiene" value="no_utiliza" label="No utiliza" />
            </div>
            <div class="row">
                <x-ui.input name="brushing_times" type="number" min="0" max="10" style="max-width:90px" />
                <span class="text-muted text-sm">veces al día</span>
            </div>
            @error('brushing_times')<div class="field__error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="field col-span-2">
            <label class="field__label">Extracciones pasadas (complicaciones)</label>
            <div class="row row--wrap" style="gap:18px">
                @foreach ($complications as $value => $label)
                    <label class="check"><input type="checkbox" name="extraction_complications[]" value="{{ $value }}" @checked(in_array($value, (array) old('extraction_complications', [])))><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
    </div>
</x-ui.card>

<x-ui.card title="HISTORIA MÉDICA" class="doc-section">
    <div class="stack">
        <x-ui.field name="under_treatment" label="¿Está actualmente bajo tratamiento médico?" as="textarea" rows="3" maxlength="1000"
                    placeholder="Especifique nombre del médico, problemas que padece y medicamentos…" />
        <div class="field">
            <label class="field__label">Padecimientos (marque si aplica)</label>
            <div class="check-grid">
                @foreach ($conditions as $value => $label)
                    <label class="check"><input type="checkbox" name="conditions[]" value="{{ $value }}" @checked(in_array($value, (array) old('conditions', [])))><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
        <x-ui.field name="allergies_detail" label="Detalle de alergias / reacciones" as="textarea" rows="2" maxlength="1000" :value="$d('allergies_detail')" />
    </div>
</x-ui.card>

<x-ui.card title="SIGNOS VITALES" class="doc-section">
    <div class="grid grid-4">
        <x-ui.field name="blood_pressure" label="Presión arterial" placeholder="Ej. 120/80" maxlength="7" />
        <x-ui.field name="pulse" label="Pulso (lpm)" type="number" min="20" max="250" placeholder="Ej. 75" />
        <x-ui.field name="respiration" label="Respiración (por min)" type="number" min="5" max="60" />
        <x-ui.field name="temperature" label="Temperatura (°C)" type="number" step="0.1" min="30" max="45" />
    </div>
    <x-ui.field class="mt-1" name="observations" label="Observaciones" as="textarea" rows="3" maxlength="2000" />
    <div class="mt-1"><x-ui.checkbox name="sync_record" value="1" label="Actualizar alergias y antecedentes del expediente con estos datos" :checked="true" /></div>
</x-ui.card>

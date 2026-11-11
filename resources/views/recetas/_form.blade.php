{{--
  Modal "Crear receta" / "Editar borrador".
  Variables: $form (mode create|edit, rx, doctors, defaultDoctor, patient, items, canEmit), $org.
--}}
@php
    $rx = $form['rx'];
    $isEdit = $form['mode'] === 'edit';
    $action = $isEdit ? route('recetas.update', $rx) : route('recetas.store');
    $patient = $form['patient'];
    $selectedDoctor = old('doctor_member_id', $form['defaultDoctor']);
    $issued = old('issued_on', $rx?->issued_on?->toDateString() ?? now()->toDateString());
    $items = $form['items'];
@endphp
<x-ui.modal id="rx-form" size="xl" static
            :title="$isEdit ? 'Editar borrador '.$rx->folio : 'Crear receta'"
            :subtitle="'Prescripción médica · '.($org?->commercial_name ?? 'Clínica')"
            :action="$action" :method="$isEdit ? 'PUT' : 'POST'" novalidate data-rx-form>

    <div class="rx-form">
        <div class="form-grid">
            {{-- Paciente (typeahead) --}}
            <div class="field">
                <label class="field__label" for="rx-patient-q">Paciente <span class="req" aria-hidden="true">*</span></label>
                <div class="rx-suggest-wrap">
                    <input type="text" id="rx-patient-q" class="input {{ $errors->has('patient_id') ? 'has-error' : '' }}"
                           value="{{ $patient['label'] ?? '' }}" placeholder="Buscar por nombre, folio, teléfono o correo…"
                           autocomplete="off" data-patient-q>
                    <div class="rx-suggest" hidden></div>
                </div>
                <input type="hidden" name="patient_id" value="{{ old('patient_id', $patient['id'] ?? '') }}" data-patient-id>
                @error('patient_id')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
            </div>

            {{-- Doctor --}}
            <div class="field">
                <label class="field__label" for="doctor_member_id">Doctor que prescribe <span class="req" aria-hidden="true">*</span></label>
                <select name="doctor_member_id" id="doctor_member_id" class="select {{ $errors->has('doctor_member_id') ? 'has-error' : '' }}">
                    <option value="">Selecciona…</option>
                    @foreach ($form['doctors'] as $doc)
                        @php $lic = $doc->doctorProfile?->professional_license; @endphp
                        <option value="{{ $doc->id }}" @selected($selectedDoctor === $doc->id)>
                            {{ $doc->user?->full_name }} · {{ $lic ? 'Céd. '.$lic : 'sin cédula' }}
                        </option>
                    @endforeach
                </select>
                @error('doctor_member_id')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Resumen del paciente + alergias --}}
        <div class="rx-patient" data-patient-summary @if (! $patient) hidden @endif>
            <span class="avatar" data-ps-avatar>{{ $patient ? collect(preg_split('/\s+/', $patient['name']))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') : '' }}</span>
            <div class="rx-patient__text">
                <strong data-ps-name>{{ $patient['name'] ?? '' }}</strong>
                <span data-ps-meta>@if ($patient) · {{ $patient['age'] !== null ? $patient['age'].' años' : 'edad no registrada' }} · {{ $patient['sex'] }}@endif</span>
            </div>
        </div>
        <div class="alert alert--warning rx-allergy" data-patient-allergy @if (! ($patient['allergies'] ?? null)) hidden @endif role="status">
            <x-ui.icon name="alert-triangle" size="sm" />
            <div><strong>Alergias registradas:</strong> <span data-allergy-text>{{ $patient['allergies'] ?? '' }}</span></div>
        </div>

        <div class="form-grid rx-grid-diag">
            <div class="field">
                <label class="field__label" for="diagnosis">Diagnóstico</label>
                <input type="text" id="diagnosis" name="diagnosis" class="input {{ $errors->has('diagnosis') ? 'has-error' : '' }}" maxlength="1000"
                       value="{{ old('diagnosis', $rx?->diagnosis) }}" placeholder="Ej. Absceso periapical en 36">
                @error('diagnosis')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="field__label" for="issued_on">Fecha <span class="req" aria-hidden="true">*</span></label>
                <input type="date" id="issued_on" name="issued_on" class="input {{ $errors->has('issued_on') ? 'has-error' : '' }}"
                       value="{{ $issued }}" max="{{ now()->toDateString() }}">
                @error('issued_on')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Medicamentos --}}
        <div class="rx-meds">
            <div class="rx-meds__head">
                <div class="row"><strong>Medicamentos</strong> <span class="badge badge--neutral" data-count>{{ count($items) }}</span></div>
                <x-ui.button variant="secondary" size="sm" icon="plus" data-row-add>Agregar medicamento</x-ui.button>
            </div>
            @error('items')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror

            <div data-rows>
                @foreach ($items as $i => $item)
                    @include('recetas._item-row', ['i' => $i, 'item' => $item])
                @endforeach
            </div>
            <template data-row-template>
                @include('recetas._item-row', ['i' => '__I__', 'item' => []])
            </template>
            <datalist id="rx-routes">
                @foreach (['Oral', 'Tópica', 'Enjuague bucal', 'Sublingual', 'Intramuscular', 'Intravenosa'] as $r)<option value="{{ $r }}">@endforeach
            </datalist>
        </div>

        <div class="field">
            <label class="field__label" for="general_instructions">Indicaciones generales <span class="field__hint-inline">Se imprimen al pie de la receta</span></label>
            <textarea id="general_instructions" name="general_instructions" class="textarea {{ $errors->has('general_instructions') ? 'has-error' : '' }}" rows="3" maxlength="2000"
                      placeholder="Reposo, higiene, señales de alarma, cita de control…">{{ old('general_instructions', $rx?->general_instructions) }}</textarea>
            @error('general_instructions')<div class="field__error" role="alert"><x-ui.icon name="alert-triangle" size="sm" />{{ $message }}</div>@enderror
        </div>
    </div>

    <x-slot name="footer">
        <button type="submit" name="action" value="vista" class="btn btn--ghost rx-link-btn">Vista de impresión</button>
        <span class="flex-1"></span>
        <button type="submit" name="action" value="borrador" class="btn btn--secondary">Guardar borrador</button>
        @if ($form['canEmit'])
            <button type="submit" name="action" value="emitir" class="btn btn--primary">Firmar y emitir</button>
        @else
            <button type="button" class="btn btn--primary" disabled title="Solo el doctor que prescribe puede firmar y emitir">Firmar y emitir</button>
        @endif
    </x-slot>
</x-ui.modal>

{{--
  Campos compartidos del paciente (alta y edición).
  Variables: $bag (bolsa de errores), $patient (null en alta), $withFiscal, $withStatus, $hasGuardian,
             $doctors, $sexOptions, $regimenes, $usosCfdi, $relationships.
--}}
@php
    $errs = $errors->getBag($bag);
    $isNew = ! $patient;
    $showGuardian = $errs->has('guardian.full_name') || $errs->has('guardian.phone') || old('guardian.full_name')
        || (! $hasGuardian && $patient && $patient->birth_date && $patient->birth_date->age < 18);
@endphp

@if ($errs->any())
    @if ($errs->has('duplicate'))
        <x-ui.alert variant="warning" title="Posible duplicado">{{ $errs->first('duplicate') }}</x-ui.alert>
        <x-ui.checkbox name="ignore_duplicates" value="1" label="Entiendo, registrar de todos modos" />
    @else
        <div class="form-error-summary" role="alert">Revisa los campos marcados en rojo.</div>
    @endif
@endif

<div class="form-grid" data-patient-form>
    <x-ui.field class="col-span-full" name="full_name" label="Nombre completo" required :bag="$bag"
                :value="$patient?->full_name" placeholder="Escribe el nombre completo" maxlength="250" autocomplete="off" />

    <x-ui.field name="birth_date" type="date" label="Fecha de nacimiento" required :bag="$bag"
                :value="$patient?->birth_date?->format('Y-m-d')" max="{{ now()->toDateString() }}" min="1900-01-01" data-birth-date />

    <x-ui.field name="sex" as="select" label="Sexo" :bag="$bag" :options="$sexOptions"
                :selected="$patient?->sex?->value ?? 'unspecified'" />

    <x-ui.field name="phone" label="Teléfono" required :bag="$bag" mask="phone" type="tel"
                :value="$patient ? \App\Services\Pacientes\PatientService::formatPhone($patient->phone) : null" placeholder="55 0000 0000" />

    <x-ui.field name="email" type="email" label="Email" :bag="$bag" :value="$patient?->email"
                placeholder="correo@ejemplo.com" maxlength="254" autocomplete="off" />

    <x-ui.field class="{{ $withStatus ? '' : 'col-span-full' }}" name="assigned_doctor_member_id" as="select"
                label="Doctor asignado" :bag="$bag" :options="$doctors" placeholder="Sin asignar"
                :selected="$patient?->assigned_doctor_member_id" />

    @if ($withStatus)
        <x-ui.field name="status" as="select" label="Estado" required :bag="$bag"
                    :options="['active' => 'Activo', 'inactive' => 'Inactivo']" :selected="$patient?->status?->value ?? 'active'" />
    @endif

    {{-- Tutor: obligatorio si el paciente es menor de 18 años (se muestra por JS según la fecha) --}}
    <div class="col-span-full stack guardian-box" data-guardian-section data-has-guardian="{{ $hasGuardian ? 1 : 0 }}" @unless($showGuardian) hidden @endunless>
        <div>
            <div class="fw-600">Tutor (paciente menor de edad)</div>
            <div class="text-sm text-muted">Obligatorio para menores de 18 años.</div>
        </div>
        <div class="form-grid">
            <x-ui.field class="col-span-full" name="guardian[full_name]" label="Nombre del tutor" :bag="$bag" placeholder="Nombre completo del tutor" maxlength="250" />
            <x-ui.field name="guardian[relationship]" as="select" label="Parentesco" :bag="$bag"
                        :options="array_combine($relationships, $relationships)" placeholder="Selecciona" />
            <x-ui.field name="guardian[phone]" label="Teléfono del tutor" :bag="$bag" mask="phone" type="tel" placeholder="55 0000 0000" />
            <x-ui.field class="col-span-full" name="guardian[email]" type="email" label="Correo del tutor" :bag="$bag" placeholder="correo@ejemplo.com" />
        </div>
    </div>

    @if ($withFiscal)
        <div class="col-span-full fw-600 mt-2">Datos fiscales (opcional)</div>
        <x-ui.field name="fiscal[rfc]" label="RFC" :bag="$bag" mask="rfc" placeholder="XAXX010101000" maxlength="13" autocomplete="off" />
        <x-ui.field name="fiscal[legal_name]" label="Razón social" :bag="$bag" placeholder="Nombre o razón social" maxlength="300" />
        <x-ui.field name="fiscal[tax_regime_code]" as="select" label="Régimen fiscal" :bag="$bag" :options="$regimenes" placeholder="Selecciona" />
        <x-ui.field name="fiscal[fiscal_postal_code]" label="Código postal fiscal" :bag="$bag" mask="postal" placeholder="00000" maxlength="5" />
        <x-ui.field class="col-span-full" name="fiscal[default_cfdi_use]" as="select" label="Uso de CFDI" :bag="$bag" :options="$usosCfdi" placeholder="Selecciona" />
    @endif
</div>

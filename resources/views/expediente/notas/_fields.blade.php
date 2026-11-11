{{-- Campos de la nota clínica (crear / editar borrador). Variables: $note (null al crear), $noteType (simple|soap), $creating. --}}
@php use App\Services\Expediente\Fmt; @endphp
<input type="hidden" name="note_type" value="{{ $noteType }}">

<div class="form-grid">
    <x-ui.field name="responsible_doctor_member_id" label="Doctor responsable" as="select" required
                :options="$doctors->mapWithKeys(fn ($m) => [$m->id => $m->user?->full_name])->all()"
                :selected="$note?->responsible_doctor_member_id ?? $defaultDoctorId"
                hint="Sólo el doctor responsable puede firmar la nota." />
    <x-ui.field name="treatment_id" label="Tratamiento" as="select" :required="$noteType === 'simple'" placeholder="Selecciona un tratamiento"
                :options="$treatments->pluck('name', 'id')->all()" :selected="$note?->treatment_id" />
    <x-ui.field name="tooth_fdi" label="Pieza dental" mask="digits" maxlength="2" placeholder="Ej. 26" :value="$note?->tooth_fdi"
                hint="Numeración FDI (11-48 permanentes, 51-85 temporales)." />
    @if ($creating)
        <x-ui.field name="appointment_id" label="Cita relacionada" as="select" placeholder="Sin cita"
                    :options="$appointments->mapWithKeys(fn ($a) => [$a->id => Fmt::dateTime($a->starts_at).' · '.($a->treatment?->name ?? 'Consulta').' ('.$a->status->label().')'])->all()"
                    :selected="request('cita')" />
        <x-ui.field name="encounter_id" label="Consulta existente" as="select" placeholder="Nueva consulta (ahora)"
                    :options="$encounters->mapWithKeys(fn ($e) => [$e->id => Fmt::dateTime($e->occurred_at)])->all()"
                    hint="Déjalo vacío para registrar una consulta nueva." />
    @endif
</div>

@if ($noteType === 'soap')
    <div class="stack soap">
        <x-ui.field name="subjective" label="Subjetivo (S)" as="textarea" rows="5" maxlength="5000" :value="$note?->subjective"
                    hint="Motivo de consulta y síntomas referidos por el paciente." />
        <x-ui.field name="objective" label="Objetivo (O)" as="textarea" rows="5" maxlength="5000" :value="$note?->objective"
                    hint="Hallazgos clínicos, exploración y pruebas." />
        <x-ui.field name="analysis" label="Análisis (A)" as="textarea" rows="4" maxlength="5000" :value="$note?->analysis"
                    hint="Diagnóstico y valoración clínica." />
        <x-ui.field name="plan" label="Plan (P)" as="textarea" rows="4" maxlength="5000" :value="$note?->plan"
                    hint="Tratamiento propuesto, indicaciones y seguimiento." />
    </div>
@else
    <x-ui.field name="objective" label="Nota" as="textarea" rows="6" required maxlength="5000" :value="$note?->objective"
                placeholder="Describe el procedimiento y los hallazgos" />
@endif

<x-ui.field name="prescription_text" label="Indicaciones al paciente (opcional)" as="textarea" rows="3" maxlength="3000" :value="$note?->prescription_text"
            hint="Texto libre; para emitir una receta formal usa el módulo Recetas." />

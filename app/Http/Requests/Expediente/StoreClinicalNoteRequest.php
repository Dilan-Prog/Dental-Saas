<?php

namespace App\Http\Requests\Expediente;

use App\Enums\NoteType;
use App\Models\MedicalRecord;
use App\Rules\FdiTooth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crea/actualiza una nota clínica (simple o SOAP).
 * `action` = guardar (borrador) | firmar (guardar y firmar).
 */
class StoreClinicalNoteRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['treatment_id', 'tooth_fdi', 'appointment_id', 'encounter_id', 'subjective', 'objective', 'analysis', 'plan', 'prescription_text']);

        // En la actualización el tipo no cambia: se toma de la nota de la ruta.
        $note = $this->route('note');
        if ($note instanceof \App\Models\ClinicalNote) {
            $this->merge(['note_type' => $note->note_type->value]);
        }
    }

    public function rules(): array
    {
        $patientId = $this->routePatient()?->id;
        $creating = ! ($this->route('note') instanceof \App\Models\ClinicalNote);

        return [
            'note_type' => ['required', Rule::in(NoteType::values())],
            'action' => ['nullable', Rule::in(['guardar', 'firmar'])],
            'responsible_doctor_member_id' => [
                'required',
                Rule::exists('doctor_profiles', 'member_id')->where('organization_id', $this->orgId()),
                // El doctor responsable debe estar activo (miembro y usuario).
                function ($attr, $value, $fail) {
                    $active = \App\Models\OrganizationMember::query()
                        ->whereKey($value)->where('is_active', true)
                        ->whereHas('user', fn ($u) => $u->where('is_active', true))
                        ->exists();
                    if (! $active) {
                        $fail('El doctor responsable no es válido.');
                    }
                },
            ],
            'treatment_id' => [
                Rule::requiredIf(fn () => $this->input('note_type') === NoteType::Simple->value),
                'nullable',
                Rule::exists('treatments', 'id')->where('organization_id', $this->orgId()),
            ],
            'tooth_fdi' => ['nullable', 'integer', new FdiTooth()],
            'appointment_id' => $creating ? [
                'nullable',
                Rule::exists('appointments', 'id')->where('organization_id', $this->orgId())->where('patient_id', $patientId),
            ] : ['prohibited'],
            'encounter_id' => $creating ? ['nullable', function ($attr, $value, $fail) use ($patientId) {
                $ok = $value && MedicalRecord::query()->where('patient_id', $patientId)
                    ->whereHas('encounters', fn ($q) => $q->where('id', $value))->exists();
                if (! $ok) {
                    $fail('La consulta seleccionada no pertenece a este paciente.');
                }
            }] : ['prohibited'],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => [
                Rule::requiredIf(fn () => $this->input('note_type') === NoteType::Simple->value),
                'nullable', 'string', 'max:5000',
            ],
            'analysis' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'prescription_text' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('note_type') !== NoteType::Soap->value || $v->errors()->isNotEmpty()) {
                return;
            }
            $any = collect(['subjective', 'objective', 'analysis', 'plan'])->contains(fn ($f) => filled($this->input($f)));
            if (! $any) {
                $v->errors()->add('subjective', 'Escribe al menos una sección de la nota SOAP.');
            }
            if ($this->input('action') === 'firmar') {
                foreach (['subjective' => 'Subjetivo (S)', 'objective' => 'Objetivo (O)', 'analysis' => 'Análisis (A)', 'plan' => 'Plan (P)'] as $f => $label) {
                    if (blank($this->input($f))) {
                        $v->errors()->add($f, "Para firmar la nota completa la sección {$label}.");
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'treatment_id.required' => 'Selecciona el tratamiento.',
            'objective.required' => 'Describe el procedimiento y los hallazgos.',
            'responsible_doctor_member_id.exists' => 'El doctor responsable no es válido.',
            'tooth_fdi.integer' => 'La pieza dental debe ser un número FDI válido (por ejemplo 26).',
        ];
    }
}

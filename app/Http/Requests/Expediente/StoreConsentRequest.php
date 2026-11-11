<?php

namespace App\Http\Requests\Expediente;

use App\Models\ClinicalFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aceptación de un consentimiento. Un documento adjunto NO equivale a aceptación:
 * la aceptación (quién, cuándo y cómo) se registra aparte.
 */
class StoreConsentRequest extends ExpedienteRequest
{
    public const METHODS = [
        'firma_autografa_escaneada' => 'Firma autógrafa (documento escaneado)',
        'firma_digital' => 'Firma digital',
        'verbal_registrada' => 'Verbal registrada por el personal',
    ];

    protected function prepareForValidation(): void
    {
        $this->nullify(['clinical_file_id', 'witness_member_id', 'accepted_at']);
        if ($this->has('accepted_by_name')) {
            $this->merge(['accepted_by_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('accepted_by_name')))]);
        }
    }

    public function rules(): array
    {
        $patientId = $this->routePatient()?->id;

        return [
            'clinical_file_id' => [
                'nullable',
                Rule::exists('clinical_files', 'id')->where('organization_id', $this->orgId())
                    ->where('patient_id', $patientId)->whereNull('deleted_at'),
            ],
            'accepted_at' => ['nullable', 'date', 'before_or_equal:now'],
            'accepted_by_name' => ['required', 'string', 'min:3', 'max:250'],
            'method' => ['required', Rule::in(array_keys(self::METHODS))],
            'witness_member_id' => ['nullable', $this->existsInOrg('organization_members')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $patient = $this->routePatient();

            if ($this->input('method') === 'firma_autografa_escaneada') {
                if (! $this->input('clinical_file_id')) {
                    $v->errors()->add('clinical_file_id', 'Adjunta el documento escaneado firmado (súbelo primero en Archivos).');
                } else {
                    $file = ClinicalFile::query()->find($this->input('clinical_file_id'));
                    if ($file && $file->file_type->value !== 'consentimiento') {
                        $v->errors()->add('clinical_file_id', 'El documento seleccionado no es de tipo consentimiento.');
                    }
                }
            }

            // Menores de edad: acepta el tutor, no el propio paciente.
            if ($error = $this->minorAcceptanceError($patient, (string) $this->input('accepted_by_name'), 'el consentimiento')) {
                $v->errors()->add('accepted_by_name', $error);
            }
        });
    }

    public function messages(): array
    {
        return [
            'accepted_by_name.required' => 'Indica quién acepta (paciente o tutor).',
            'accepted_at.before_or_equal' => 'La fecha de aceptación no puede ser futura.',
            'method.required' => 'Selecciona el método de aceptación.',
        ];
    }
}

<?php

namespace App\Http\Requests\Expediente;

/** Historial médico: alergias y antecedentes (expediente.historial.update). */
class UpdateMedicalHistoryRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['allergies', 'medical_history']);
    }

    public function rules(): array
    {
        return [
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

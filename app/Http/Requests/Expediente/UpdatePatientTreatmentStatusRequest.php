<?php

namespace App\Http\Requests\Expediente;

use App\Enums\PlannedTreatmentStatus;
use Illuminate\Validation\Rule;

/** Cambio de estado de un tratamiento del paciente. */
class UpdatePatientTreatmentStatusRequest extends ExpedienteRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(PlannedTreatmentStatus::values())],
        ];
    }
}

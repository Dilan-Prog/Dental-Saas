<?php

namespace App\Http\Requests\Expediente;

use App\Enums\PlannedTreatmentStatus;
use App\Rules\FdiTooth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Agrega un tratamiento al plan del paciente. */
class StorePatientTreatmentRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['tooth_fdi', 'started_at', 'completed_at']);
        if (! $this->filled('status')) {
            $this->merge(['status' => PlannedTreatmentStatus::Planeado->value]);
        }
    }

    public function rules(): array
    {
        return [
            'treatment_id' => ['required', Rule::exists('treatments', 'id')->where('organization_id', $this->orgId())->where('status', 'active')],
            'tooth_fdi' => ['nullable', 'integer', new FdiTooth()],
            'status' => ['required', Rule::in(PlannedTreatmentStatus::values())],
            'started_at' => ['nullable', 'date', 'before_or_equal:now'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at', 'before_or_equal:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $status = $this->input('status');
            if ($this->filled('completed_at') && $status !== PlannedTreatmentStatus::Realizado->value) {
                $v->errors()->add('completed_at', 'La fecha de término sólo aplica a tratamientos realizados.');
            }
            if ($this->filled('started_at') && $status === PlannedTreatmentStatus::Planeado->value) {
                $v->errors()->add('started_at', 'Un tratamiento planeado aún no tiene fecha de inicio.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'treatment_id.required' => 'Selecciona el tratamiento.',
            'treatment_id.exists' => 'El tratamiento no existe o está inactivo.',
            'completed_at.after_or_equal' => 'La fecha de término no puede ser anterior a la de inicio.',
        ];
    }
}

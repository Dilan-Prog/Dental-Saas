<?php

namespace App\Http\Requests\Expediente;

use App\Enums\DentitionType;
use App\Enums\ToothCondition;
use App\Models\MedicalRecord;
use App\Rules\FdiTooth;
use App\Services\Expediente\OdontogramService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Estado de una pieza dental (odontograma). */
class StoreToothFindingRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['patient_treatment_id', 'encounter_id', 'dentition']);
        if (! is_array($this->input('surfaces'))) {
            $this->merge(['surfaces' => []]);
        }
    }

    public function rules(): array
    {
        $patientId = $this->routePatient()?->id;

        return [
            'tooth_fdi' => ['required', 'integer', new FdiTooth($this->input('dentition'))],
            'dentition' => ['nullable', Rule::in(DentitionType::values())],
            'condition' => ['required', Rule::in(ToothCondition::values())],
            'surfaces' => ['nullable', 'array', 'max:6'],
            'surfaces.*' => ['string', Rule::in(OdontogramService::SURFACES), 'distinct'],
            'patient_treatment_id' => [
                'nullable',
                Rule::exists('patient_treatments', 'id')->where('organization_id', $this->orgId())->where('patient_id', $patientId),
            ],
            'encounter_id' => ['nullable', function ($attr, $value, $fail) use ($patientId) {
                $ok = $value && MedicalRecord::query()->where('patient_id', $patientId)
                    ->whereHas('encounters', fn ($q) => $q->where('id', $value))->exists();
                if (! $ok) {
                    $fail('La consulta seleccionada no pertenece a este paciente.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $condition = $this->input('condition');
            if (! in_array($condition, OdontogramService::WITH_SURFACES, true) && count($this->input('surfaces', [])) > 0) {
                $v->errors()->add('surfaces', 'Las superficies sólo aplican a caries, restauración o endodoncia.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'tooth_fdi.required' => 'Selecciona la pieza dental.',
            'condition.required' => 'Selecciona la condición de la pieza.',
            'surfaces.*.in' => 'Superficie no válida (M, D, O, V, L o P).',
        ];
    }
}

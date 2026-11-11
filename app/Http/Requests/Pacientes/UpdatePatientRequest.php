<?php

namespace App\Http\Requests\Pacientes;

use App\Enums\RecordStatus;
use App\Http\Requests\Pacientes\Concerns\ValidatesPatientData;
use App\Models\Patient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientRequest extends FormRequest
{
    use ValidatesPatientData;

    protected $errorBag = 'patientEdit';

    public function authorize(): bool
    {
        return true; // permiso pacientes.editar en la ruta
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePatientInput();
    }

    private function patient(): Patient
    {
        return $this->route('patient');
    }

    public function rules(): array
    {
        // El tutor solo es obligatorio si ahora es menor de edad y aún no tiene ninguno.
        $needsGuardian = $this->isMinorInput() && $this->patient()->guardians()->doesntExist();

        return $this->personalRules()
            + $this->guardianRules($needsGuardian)
            + ['status' => ['required', Rule::in(RecordStatus::values())]];
    }

    public function withValidator(Validator $validator): void
    {
        $this->afterPatientValidation($validator, $this->patient());
    }

    public function messages(): array
    {
        return $this->patientMessages() + ['status.required' => 'Selecciona el estado del paciente.'];
    }

    public function attributes(): array
    {
        return $this->patientAttributes() + ['status' => 'estado'];
    }
}

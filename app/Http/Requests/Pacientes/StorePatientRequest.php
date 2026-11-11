<?php

namespace App\Http\Requests\Pacientes;

use App\Http\Requests\Pacientes\Concerns\ValidatesPatientData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
{
    use ValidatesPatientData;

    /** Los errores van a una bolsa propia para que solo se abra el modal "Nuevo paciente". */
    protected $errorBag = 'patientNew';

    public function authorize(): bool
    {
        return true; // el permiso pacientes.crear lo aplica el middleware de la ruta
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePatientInput();
    }

    public function rules(): array
    {
        return $this->personalRules()
            + $this->fiscalRules()
            + $this->guardianRules($this->isMinorInput());
    }

    public function withValidator(Validator $validator): void
    {
        $this->afterPatientValidation($validator);
    }

    public function messages(): array
    {
        return $this->patientMessages();
    }

    public function attributes(): array
    {
        return $this->patientAttributes();
    }
}

<?php

namespace App\Http\Requests\Expediente;

use App\Rules\MxPhone;

/** Modal "Editar datos del paciente" del expediente (nombre, nacimiento, teléfono, correo). */
class UpdatePatientBasicRequest extends ExpedienteRequest
{
    protected function prepareForValidation(): void
    {
        $this->nullify(['email']);
        if ($this->has('full_name')) {
            $this->merge(['full_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('full_name')))]);
        }
        if ($this->has('email') && $this->input('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:250', "regex:/^[\\pL\\pM .'’-]+$/u"],
            'birth_date' => ['required', 'date', 'after:1900-01-01', 'before_or_equal:today'],
            'phone' => ['required', 'string', 'max:30', new MxPhone()],
            'email' => ['nullable', 'email:rfc', 'max:254'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            if ($v->errors()->has('birth_date') || ! ($patient = $this->routePatient())) {
                return;
            }
            // Un paciente que pasa a ser menor de edad debe tener tutor (misma regla que Pacientes).
            $birth = \Illuminate\Support\Carbon::parse($this->input('birth_date'));
            if ($birth->age < 18 && ! \App\Models\PatientGuardian::query()->where('patient_id', $patient->id)->exists()) {
                $v->errors()->add('birth_date', 'Con esa fecha el paciente sería menor de edad: registra primero a su tutor en Pacientes.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'full_name.regex' => 'El nombre sólo puede contener letras, espacios, apóstrofos y guiones.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'birth_date.after' => 'La fecha de nacimiento no es válida.',
        ];
    }
}

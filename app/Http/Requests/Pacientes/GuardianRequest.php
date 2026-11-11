<?php

namespace App\Http\Requests\Pacientes;

use App\Models\Patient;
use App\Rules\MxPhone;
use App\Services\Pacientes\PatientService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crea (sin guardian_id) o edita (con guardian_id) un tutor del paciente. */
class GuardianRequest extends FormRequest
{
    protected $errorBag = 'guardian';

    public function authorize(): bool
    {
        return true; // permiso pacientes.editar en la ruta
    }

    protected function prepareForValidation(): void
    {
        $phone = (string) $this->input('phone');

        $this->merge([
            'full_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('full_name'))),
            'phone' => $phone !== '' ? (MxPhone::normalize($phone) ?? $phone) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    public function rules(): array
    {
        /** @var Patient $patient */
        $patient = $this->route('patient');

        return [
            'guardian_id' => ['nullable', 'uuid', Rule::exists('patient_guardians', 'id')->where('patient_id', $patient->id)],
            'full_name' => ['required', 'string', 'min:3', 'max:250', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'\\-]*$/u"],
            'relationship' => ['nullable', 'string', Rule::in(PatientService::RELATIONSHIPS)],
            'phone' => ['nullable', 'required_without:email', 'string', new MxPhone()],
            'email' => ['nullable', 'required_without:phone', 'string', 'email:rfc', 'max:254'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'El nombre del tutor es obligatorio.',
            'full_name.regex' => 'El nombre solo puede contener letras, espacios, punto, apóstrofo y guion.',
            'phone.required_without' => 'Captura el teléfono o el correo del tutor.',
            'email.required_without' => 'Captura el correo o el teléfono del tutor.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'guardian_id.exists' => 'El tutor no existe.',
        ];
    }

    public function attributes(): array
    {
        return ['full_name' => 'nombre', 'phone' => 'teléfono', 'email' => 'correo', 'relationship' => 'parentesco'];
    }
}

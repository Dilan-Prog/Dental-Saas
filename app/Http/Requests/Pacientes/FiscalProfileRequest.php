<?php

namespace App\Http\Requests\Pacientes;

use App\Models\Patient;
use App\Rules\CfdiCatalogs;
use App\Rules\PostalCodeMx;
use App\Rules\Rfc;
use App\Services\Pacientes\FiscalCoherence;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crea (sin profile_id) o edita (con profile_id) un perfil fiscal del paciente. */
class FiscalProfileRequest extends FormRequest
{
    protected $errorBag = 'fiscal';

    public function authorize(): bool
    {
        return true; // permiso pacientes.editar en la ruta
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rfc' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('rfc'))),
            'legal_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('legal_name'))),
            'fiscal_postal_code' => preg_replace('/\D+/', '', (string) $this->input('fiscal_postal_code')),
        ]);
    }

    public function rules(): array
    {
        /** @var Patient $patient */
        $patient = $this->route('patient');
        $profileId = $this->input('profile_id') ?: null;

        return [
            'profile_id' => ['nullable', 'uuid', Rule::exists('patient_fiscal_profiles', 'id')->where('patient_id', $patient->id)],
            'rfc' => [
                'required', 'string', 'max:13', new Rfc(),
                Rule::unique('patient_fiscal_profiles', 'rfc')->where('patient_id', $patient->id)->ignore($profileId),
            ],
            'legal_name' => ['required', 'string', 'min:2', 'max:300'],
            'tax_regime_code' => ['required', 'string', new CfdiCatalogs('regimenes')],
            'fiscal_postal_code' => ['required', 'string', new PostalCodeMx()],
            'default_cfdi_use' => ['nullable', 'string', new CfdiCatalogs('usos_cfdi')],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $err = FiscalCoherence::check($this->input('rfc'), $this->input('tax_regime_code'));
            if ($err && ! $v->errors()->has('tax_regime_code')) {
                $v->errors()->add('tax_regime_code', $err);
            }
        });
    }

    public function messages(): array
    {
        return [
            'rfc.required' => 'El RFC es obligatorio.',
            'rfc.unique' => 'Este RFC ya está registrado para el paciente.',
            'legal_name.required' => 'La razón social es obligatoria.',
            'tax_regime_code.required' => 'Selecciona el régimen fiscal.',
            'fiscal_postal_code.required' => 'El código postal fiscal es obligatorio.',
            'profile_id.exists' => 'El perfil fiscal no existe.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rfc' => 'RFC',
            'legal_name' => 'razón social',
            'tax_regime_code' => 'régimen fiscal',
            'fiscal_postal_code' => 'código postal fiscal',
            'default_cfdi_use' => 'uso de CFDI',
        ];
    }
}

<?php

namespace App\Http\Requests\Pacientes;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

/** La anonimización es irreversible: solo administradores y con el folio escrito como confirmación. */
class AnonymizePatientRequest extends FormRequest
{
    protected $errorBag = 'anonymize';

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        /** @var Patient $patient */
        $patient = $this->route('patient');

        return [
            'confirm_folio' => ['required', 'string', 'in:'.$patient->folio],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_folio.required' => 'Escribe el folio del paciente para confirmar.',
            'confirm_folio.in' => 'El folio no coincide con el del paciente.',
        ];
    }
}

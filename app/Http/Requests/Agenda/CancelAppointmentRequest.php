<?php

namespace App\Http\Requests\Agenda;

use Illuminate\Foundation\Http\FormRequest;

class CancelAppointmentRequest extends FormRequest
{
    /** Errores en su propia bolsa (modal «Cancelar cita»). */
    protected $errorBag = 'cancel';

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('agenda', 'editar');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => is_string($this->reason) ? trim($this->reason) : $this->reason]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indica el motivo de la cancelación.',
            'reason.min' => 'El motivo debe tener al menos :min caracteres.',
            'reason.max' => 'El motivo no puede superar :max caracteres.',
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}

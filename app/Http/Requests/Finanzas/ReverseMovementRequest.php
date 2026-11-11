<?php

namespace App\Http\Requests\Finanzas;

class ReverseMovementRequest extends FinanzasRequest
{
    protected $errorBag = 'reverse';

    protected function prepareForValidation(): void
    {
        $this->trimStrings('reason');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indica el motivo de la reversion.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
            'reason.max' => 'El motivo no puede superar 200 caracteres.',
        ];
    }
}

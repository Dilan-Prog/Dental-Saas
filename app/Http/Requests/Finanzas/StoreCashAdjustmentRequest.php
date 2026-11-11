<?php

namespace App\Http\Requests\Finanzas;

/** Ajuste firmado sobre un turno cerrado (puede ser negativo). */
class StoreCashAdjustmentRequest extends FinanzasRequest
{
    protected $errorBag = 'adjust';

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney('amount');
        $this->trimStrings('reason');
    }

    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', 'numeric', 'not_in:0', 'between:-99999999999.99,99999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'El monto del ajuste es obligatorio.',
            'amount.numeric' => 'El monto del ajuste debe ser un importe numerico (puede ser negativo).',
            'amount.not_in' => 'El ajuste no puede ser de $0.00.',
            'amount.between' => 'El monto del ajuste esta fuera del rango permitido.',
            'amount.decimal' => 'El monto del ajuste admite como maximo 2 decimales.',
            'reason.required' => 'Explica el motivo del ajuste.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
            'reason.max' => 'El motivo no puede superar 500 caracteres.',
        ];
    }
}

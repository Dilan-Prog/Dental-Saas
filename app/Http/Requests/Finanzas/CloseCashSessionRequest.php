<?php

namespace App\Http\Requests\Finanzas;

/** Corte de caja: efectivo contado >= 0; las notas se exigen en el servicio si hay diferencia. */
class CloseCashSessionRequest extends FinanzasRequest
{
    protected $errorBag = 'closeCash';

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney('counted_cash');
        $this->trimStrings('notes');
    }

    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return $this->moneyMessages('counted_cash', 'efectivo contado') + [
            'notes.max' => 'Las notas no pueden superar 1000 caracteres.',
        ];
    }
}

<?php

namespace App\Http\Requests\Finanzas;

use Illuminate\Validation\Rule;

/** Abrir turno de caja: monto inicial >= 0. */
class OpenCashSessionRequest extends FinanzasRequest
{
    protected $errorBag = 'openCash';

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney('opening_amount');
        if (! $this->filled('opening_amount')) {
            $this->merge(['opening_amount' => '0']);
        }
    }

    public function rules(): array
    {
        return [
            'cash_register_id' => ['required', 'uuid', Rule::exists('cash_registers', 'id')->where('organization_id', $this->organizationId())->where('status', 'active')],
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return $this->moneyMessages('opening_amount', 'monto inicial') + [
            'cash_register_id.required' => 'Selecciona la caja.',
            'cash_register_id.exists' => 'La caja no existe o esta inactiva.',
        ];
    }
}

<?php

namespace App\Http\Requests\Inventario;

use Illuminate\Validation\Validator;

/** Ajuste con signo (+ suma, - resta). Motivo obligatorio. Bolsa de errores: ajuste. */
class AjusteRequest extends InventarioRequest
{
    protected $errorBag = 'ajuste';

    protected array $trimFields = ['reference', 'reason'];

    protected array $numericFields = ['quantity', 'unit_cost'];

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', $this->itemExists()],
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'quantity' => ['required', 'numeric', 'regex:/^-?\d{1,10}(\.\d{1,4})?$/'],
            'unit_cost' => $this->nonNegativeQty(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lot_id' => ['nullable', 'uuid', $this->lotExists()],
            'encounter_id' => ['nullable', 'uuid', $this->encounterExists()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $q = $this->input('quantity');
            if (is_numeric($q) && (float) $q == 0.0) {
                $v->errors()->add('quantity', 'El ajuste no puede ser cero; use un valor positivo para sumar o negativo para restar.');
            }
        });
    }

    public function messages(): array
    {
        return parent::messages() + [
            'quantity.regex' => 'La cantidad admite signo, hasta 10 enteros y 4 decimales.',
            'reason.required' => 'Indique el motivo del ajuste.',
        ];
    }
}

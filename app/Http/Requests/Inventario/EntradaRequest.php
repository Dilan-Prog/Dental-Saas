<?php

namespace App\Http\Requests\Inventario;

/** Registrar entrada (modal "Registrar entrada"). Bolsa de errores: entrada. */
class EntradaRequest extends InventarioRequest
{
    protected $errorBag = 'entrada';

    protected array $trimFields = ['reference'];

    protected array $upperFields = ['lot_code'];

    protected array $numericFields = ['quantity', 'unit_cost'];

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', $this->itemExists()],
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'quantity' => $this->positiveQty(),
            'unit_cost' => $this->nonNegativeQty(true),
            'supplier_id' => ['nullable', 'uuid', $this->supplierExists()],
            'reference' => ['nullable', 'string', 'max:120'],
            'lot_code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\-\/ ]+$/', 'required_with:expires_on'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'encounter_id' => ['nullable', 'uuid', $this->encounterExists()],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'unit_cost.required' => 'Indique el costo unitario de la compra.',
            'expires_on.date_format' => 'La caducidad debe tener formato de fecha válido.',
        ];
    }
}

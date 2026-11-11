<?php

namespace App\Http\Requests\Inventario;

/** Inventario inicial de un insumo existente (una sola vez por sucursal). Bolsa de errores: inicial. */
class InicialRequest extends InventarioRequest
{
    protected $errorBag = 'inicial';

    protected array $trimFields = ['reference'];

    protected array $numericFields = ['quantity', 'unit_cost'];

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', $this->itemExists()],
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'quantity' => $this->positiveQty(),
            'unit_cost' => $this->nonNegativeQty(true),
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}

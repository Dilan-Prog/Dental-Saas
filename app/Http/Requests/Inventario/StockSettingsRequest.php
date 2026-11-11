<?php

namespace App\Http\Requests\Inventario;

/** Stock minimo y ubicacion de un insumo en una sucursal. Bolsa de errores: stock. */
class StockSettingsRequest extends InventarioRequest
{
    protected $errorBag = 'stock';

    protected array $trimFields = ['location'];

    protected array $numericFields = ['min_quantity'];

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'min_quantity' => $this->nonNegativeQty(true),
            'location' => ['nullable', 'string', 'max:100'],
        ];
    }
}

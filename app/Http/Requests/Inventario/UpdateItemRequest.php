<?php

namespace App\Http\Requests\Inventario;

use Illuminate\Validation\Rule;

/** Edicion de datos del insumo (no toca existencias). Bolsa de errores: insumo_edit. */
class UpdateItemRequest extends InventarioRequest
{
    protected $errorBag = 'insumo_edit';

    protected array $trimFields = ['name', 'category', 'unit_of_measure'];

    protected array $upperFields = ['sku'];

    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\-\/]+$/',
                Rule::unique('inventory_items', 'sku')->where('organization_id', $this->org())->ignore($item?->id),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_of_measure' => ['required', 'string', 'max:30'],
            'supplier_id' => ['nullable', 'uuid', $this->supplierExists()],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['sku.unique' => 'Ya existe un insumo con ese SKU.'];
    }
}

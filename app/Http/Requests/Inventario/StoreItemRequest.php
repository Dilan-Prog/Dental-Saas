<?php

namespace App\Http\Requests\Inventario;

use Illuminate\Validation\Rule;

/** Alta de insumo (modal "Nuevo insumo"). Bolsa de errores: insumo. */
class StoreItemRequest extends InventarioRequest
{
    protected $errorBag = 'insumo';

    protected array $trimFields = ['name', 'category', 'unit_of_measure', 'location'];

    protected array $upperFields = ['sku'];

    protected array $numericFields = ['initial_stock', 'min_quantity', 'unit_cost'];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'sku' => [
                'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\-\/]+$/',
                Rule::unique('inventory_items', 'sku')->where('organization_id', $this->org()),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_of_measure' => ['required', 'string', 'max:30'],
            'supplier_id' => ['nullable', 'uuid', $this->supplierExists()],
            'branch_id' => ['required', 'uuid', $this->branchExists()],
            'initial_stock' => $this->nonNegativeQty(),
            'min_quantity' => $this->nonNegativeQty(),
            // Con stock inicial el costo es obligatorio: alimenta el costo promedio ponderado.
            'unit_cost' => array_merge($this->nonNegativeQty(), ['required_with:initial_stock']),
            'location' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'sku.unique' => 'Ya existe un insumo con ese SKU.',
            'unit_cost.required_with' => 'Indique el costo unitario del stock inicial.',
        ];
    }

    /** Si el stock inicial es 0 o vacio no exigimos costo. */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $initial = $this->input('initial_stock');
        if ($initial === null || $initial === '' || (is_numeric($initial) && (float) $initial == 0.0)) {
            $this->merge(['initial_stock' => null]);
        }
    }
}

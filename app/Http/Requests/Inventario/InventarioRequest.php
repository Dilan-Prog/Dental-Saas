<?php

namespace App\Http\Requests\Inventario;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base de los formularios del inventario: normalizacion de numeros/textos,
 * reglas comunes (existencia dentro de la organizacion) y etiquetas en español.
 * El permiso por accion lo aplica el middleware de la ruta.
 */
abstract class InventarioRequest extends FormRequest
{
    /** Campos numericos que se limpian de $, comas y espacios antes de validar. */
    protected array $numericFields = [];

    /** Campos de texto que se recortan; los de mayusculas se normalizan aparte. */
    protected array $trimFields = [];

    protected array $upperFields = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach ($this->trimFields as $f) {
            if ($this->has($f) && is_string($this->input($f))) {
                $v = trim(preg_replace('/\s+/u', ' ', $this->input($f)) ?? '');
                $merge[$f] = $v === '' ? null : $v;
            }
        }
        foreach ($this->upperFields as $f) {
            if ($this->has($f) && is_string($this->input($f))) {
                $v = mb_strtoupper(trim($this->input($f)));
                $merge[$f] = $v === '' ? null : $v;
            }
        }
        foreach ($this->numericFields as $f) {
            if ($this->has($f) && is_string($this->input($f))) {
                $v = str_replace(['$', ',', ' '], '', trim($this->input($f)));
                $merge[$f] = $v === '' ? null : $v;
            }
        }
        foreach (['supplier_id', 'encounter_id', 'lot_id', 'expires_on'] as $f) {
            if ($this->has($f) && $this->input($f) === '') {
                $merge[$f] = null;
            }
        }

        $this->merge($merge);
    }

    protected function org(): ?string
    {
        return Organization::currentId();
    }

    // -------- reglas reutilizables --------

    /** Cantidad positiva con hasta 4 decimales y 10 enteros (decimal 14,4). */
    protected function positiveQty(): array
    {
        return ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'];
    }

    /** Cantidad >= 0 opcional. */
    protected function nonNegativeQty(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'];
    }

    protected function itemExists(bool $onlyActive = true)
    {
        return Rule::exists('inventory_items', 'id')
            ->where('organization_id', $this->org())
            ->when($onlyActive, fn ($r) => $r->where('status', 'active'));
    }

    protected function branchExists()
    {
        return Rule::exists('branches', 'id')->where('organization_id', $this->org())->where('status', 'active');
    }

    protected function supplierExists()
    {
        return Rule::exists('suppliers', 'id')->where('organization_id', $this->org());
    }

    protected function encounterExists()
    {
        return Rule::exists('clinical_encounters', 'id')->where('organization_id', $this->org());
    }

    protected function lotExists()
    {
        return Rule::exists('inventory_lots', 'id')->where('organization_id', $this->org());
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'sku' => 'SKU',
            'category' => 'categoría',
            'unit_of_measure' => 'unidad de medida',
            'supplier_id' => 'proveedor',
            'branch_id' => 'sucursal',
            'initial_stock' => 'stock inicial',
            'min_quantity' => 'stock mínimo',
            'unit_cost' => 'costo unitario',
            'location' => 'ubicación',
            'item_id' => 'insumo',
            'quantity' => 'cantidad',
            'reference' => 'referencia',
            'reason' => 'motivo',
            'lot_code' => 'lote',
            'lot_id' => 'lote',
            'expires_on' => 'caducidad',
            'encounter_id' => 'consulta',
            'contact_phone' => 'teléfono',
            'contact_email' => 'correo',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.gt' => 'La cantidad debe ser mayor a cero.',
            'quantity.regex' => 'La cantidad admite hasta 10 enteros y 4 decimales.',
            'quantity.numeric' => 'La cantidad debe ser un número.',
            'initial_stock.regex' => 'El stock inicial admite hasta 10 enteros y 4 decimales.',
            'min_quantity.regex' => 'El stock mínimo admite hasta 10 enteros y 4 decimales.',
            'unit_cost.regex' => 'El costo unitario admite hasta 10 enteros y 4 decimales.',
            'unit_cost.min' => 'El costo unitario no puede ser negativo.',
            'min_quantity.min' => 'El stock mínimo no puede ser negativo.',
            'initial_stock.min' => 'El stock inicial no puede ser negativo.',
            'sku.regex' => 'El SKU solo admite letras, números, punto, guion, guion bajo y diagonal.',
            'lot_code.regex' => 'El lote solo admite letras, números, espacios, punto, guion y diagonal.',
            'lot_code.required_with' => 'Indique el código de lote para registrar su caducidad.',
            'expires_on.after_or_equal' => 'La caducidad no puede ser anterior a hoy.',
            'item_id.exists' => 'El insumo no existe o está inactivo.',
            'branch_id.exists' => 'La sucursal no existe o está inactiva.',
        ];
    }
}

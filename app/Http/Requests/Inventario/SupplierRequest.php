<?php

namespace App\Http\Requests\Inventario;

use App\Rules\MxPhone;
use Illuminate\Validation\Rule;

/**
 * Alta y edicion de proveedores. Bolsa de errores: proveedor (alta) / proveedor_edit (edicion).
 * El nombre es unico por consultorio sin distinguir mayusculas (colacion ci).
 */
class SupplierRequest extends InventarioRequest
{
    protected array $trimFields = ['name', 'contact_phone', 'contact_email'];

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $this->errorBag = $this->route('supplier') ? 'proveedor_edit' : 'proveedor';

        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:200',
                Rule::unique('suppliers', 'name')->where('organization_id', $this->org())->ignore($supplier?->id),
            ],
            'contact_phone' => ['nullable', 'string', 'max:30', new MxPhone()],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'name.unique' => 'Ya existe un proveedor con ese nombre.',
            'contact_email.email' => 'El correo no tiene un formato válido.',
        ];
    }
}

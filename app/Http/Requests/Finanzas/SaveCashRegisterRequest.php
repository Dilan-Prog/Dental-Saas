<?php

namespace App\Http\Requests\Finanzas;

use App\Models\CashRegister;
use Illuminate\Validation\Rule;

/** Crear / editar caja (nombre unico por sucursal). */
class SaveCashRegisterRequest extends FinanzasRequest
{
    protected $errorBag = 'register';

    protected function prepareForValidation(): void
    {
        $this->trimStrings('name');
    }

    public function rules(): array
    {
        /** @var CashRegister|null $register */
        $register = $this->route('register');
        $org = $this->organizationId();

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('cash_registers', 'name')
                    ->where('organization_id', $org)
                    ->where('branch_id', $this->input('branch_id', $register?->branch_id))
                    ->ignore($register?->id),
            ],
            'branch_id' => [$register ? 'prohibited' : 'required', 'uuid', Rule::exists('branches', 'id')->where('organization_id', $org)->where('status', 'active')],
            'status' => [$register ? 'required' : 'prohibited', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la caja es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar 100 caracteres.',
            'name.unique' => 'Ya existe una caja con ese nombre en la sucursal.',
            'branch_id.required' => 'Selecciona la sucursal.',
            'branch_id.exists' => 'La sucursal no existe o esta inactiva.',
            'branch_id.prohibited' => 'La sucursal de una caja existente no se puede cambiar.',
            'status.required' => 'Indica si la caja esta activa o inactiva.',
            'status.in' => 'El estado no es valido.',
            'status.prohibited' => 'El estado se define al editar la caja.',
        ];
    }
}

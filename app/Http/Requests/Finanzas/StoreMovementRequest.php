<?php

namespace App\Http\Requests\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

/** Nueva transaccion manual (ingreso / egreso). */
class StoreMovementRequest extends FinanzasRequest
{
    protected $errorBag = 'movement';

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney('amount');
        $this->trimStrings('concept');

        // Sin sucursal explicita se usa la principal.
        if (! $this->filled('branch_id')) {
            $main = \App\Models\Branch::query()->where('is_main', true)->value('id');
            if ($main) {
                $this->merge(['branch_id' => $main]);
            }
        }
    }

    public function rules(): array
    {
        $org = $this->organizationId();
        $direction = $this->input('direction');

        return [
            'submission_key' => ['nullable', 'uuid'],
            'direction' => ['required', Rule::in(MovementDirection::values())],
            'category_id' => [
                'required', 'uuid',
                Rule::exists('financial_categories', 'id')
                    ->where('organization_id', $org)
                    ->where('status', 'active')
                    ->where('direction', in_array($direction, MovementDirection::values(), true) ? $direction : '__none__'),
            ],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'concept' => ['required', 'string', 'min:3', 'max:300'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('organization_id', $org)->where('status', 'active')],
            'cash_session_id' => ['nullable', 'uuid', Rule::exists('cash_sessions', 'id')->where('organization_id', $org)],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'direction' => 'tipo de movimiento',
            'category_id' => 'categoria',
            'method' => 'metodo de pago',
            'concept' => 'concepto',
            'amount' => 'monto',
            'occurred_on' => 'fecha',
            'branch_id' => 'sucursal',
            'receipt' => 'comprobante',
        ];
    }

    public function messages(): array
    {
        return $this->moneyMessages('amount', 'monto') + [
            'direction.required' => 'Selecciona si es un ingreso o un egreso.',
            'direction.in' => 'El tipo de movimiento no es valido.',
            'category_id.required' => 'Selecciona una categoria.',
            'category_id.exists' => 'La categoria no existe, esta inactiva o no corresponde al tipo de movimiento.',
            'method.required' => 'Selecciona el metodo de pago.',
            'method.in' => 'El metodo de pago no es valido.',
            'concept.required' => 'Describe el concepto del movimiento.',
            'concept.min' => 'El concepto debe tener al menos 3 caracteres.',
            'concept.max' => 'El concepto no puede superar 300 caracteres.',
            'occurred_on.required' => 'Indica la fecha del movimiento.',
            'occurred_on.date_format' => 'La fecha no es valida (use AAAA-MM-DD).',
            'occurred_on.before_or_equal' => 'La fecha no puede ser futura.',
            'occurred_on.after_or_equal' => 'La fecha no puede ser anterior al 2000.',
            'branch_id.required' => 'Selecciona la sucursal.',
            'branch_id.exists' => 'La sucursal no existe o esta inactiva.',
            'cash_session_id.exists' => 'La caja seleccionada no existe.',
            'receipt.mimes' => 'El comprobante debe ser JPG, PNG o PDF.',
            'receipt.max' => 'El comprobante no puede pesar mas de 5 MB.',
            'receipt.file' => 'El comprobante no es un archivo valido.',
        ];
    }
}

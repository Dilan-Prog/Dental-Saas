<?php

namespace App\Http\Requests\Facturacion;

use Illuminate\Foundation\Http\FormRequest;

/** Reversion de un pago: el motivo es obligatorio y queda en payment_reversals. */
class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Una bolsa por pago: cada pago tiene su propio modal de reversion.
        $payment = $this->route('payment');
        $id = is_object($payment) ? $payment->getKey() : $payment;
        $this->errorBag = 'reversion_' . $id;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Describe el motivo de la reversion (minimo 5 caracteres).'];
    }
}

<?php

namespace App\Http\Requests\Facturacion;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cancelacion de factura con motivo SAT (01-04). El motivo 01 exige factura sustituta. */
class CancelInvoiceRequest extends FormRequest
{
    protected $errorBag = 'cancelacion';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['substitute_invoice_id' => $this->input('substitute_invoice_id') ?: null]);
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::in(array_keys(config('sat.motivos_cancelacion')))],
            'substitute_invoice_id' => [
                Rule::requiredIf(fn () => $this->input('reason_code') === '01'),
                'nullable', 'uuid',
                Rule::exists('invoices', 'id')->where('organization_id', Organization::currentId()),
            ],
            'detail' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['reason_code' => 'motivo de cancelacion', 'substitute_invoice_id' => 'factura sustituta', 'detail' => 'comentario'];
    }

    public function messages(): array
    {
        return ['substitute_invoice_id.required' => 'El motivo 01 requiere indicar la factura que sustituye a la cancelada.'];
    }
}

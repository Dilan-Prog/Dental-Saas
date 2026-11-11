<?php

namespace App\Http\Requests\Facturacion;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registro de pago. Dos formas:
 *  - desde una factura (ruta pagos.store, {invoice}): campo `amount`.
 *  - multiple (pagos.storeMulti): `patient_id` + `applications[invoice_id] = monto`.
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isMulti(): bool
    {
        return $this->route('invoice') === null;
    }

    protected function prepareForValidation(): void
    {
        // El modal "Registrar pago" del detalle usa su propia bolsa de errores (se reabre solo).
        $this->errorBag = $this->isMulti() ? 'default' : 'pago';

        $clean = fn ($v) => is_string($v) ? preg_replace('/[^0-9.\-]/', '', $v) : $v;

        $data = ['reference' => ($r = trim((string) $this->input('reference'))) === '' ? null : $r];
        if ($this->has('amount')) {
            $data['amount'] = $clean($this->input('amount'));
        }
        if (is_array($this->input('applications'))) {
            $data['applications'] = collect($this->input('applications'))
                ->map($clean)->filter(fn ($v) => $v !== '' && $v !== null)->all();
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $rules = [
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->input('method') === 'transferencia')],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];

        if ($this->isMulti()) {
            $rules += [
                'patient_id' => ['required', 'uuid', Rule::exists('patients', 'id')->where('organization_id', $org)],
                'applications' => ['required', 'array', 'min:1'],
                'applications.*' => ['numeric', 'decimal:0,2', 'gt:0', 'max:99999999999.99'],
            ];
        } else {
            $rules['amount'] = ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999999.99'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'amount' => 'monto', 'method' => 'metodo de pago', 'reference' => 'referencia',
            'paid_at' => 'fecha del pago', 'patient_id' => 'paciente', 'applications' => 'facturas',
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required' => 'La referencia es obligatoria en transferencias.',
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'applications.required' => 'Indica el monto a pagar en al menos una factura.',
            'applications.min' => 'Indica el monto a pagar en al menos una factura.',
            'applications.*.gt' => 'Cada monto debe ser mayor a cero.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Referencia de transferencia unica (evita pagos duplicados).
            if ($this->input('method') === 'transferencia' && $this->input('reference')
                && Payment::where('method', 'transferencia')->where('reference', $this->input('reference'))->exists()) {
                $v->errors()->add('reference', 'La referencia de transferencia ya fue registrada en otro pago.');
            }

            // Monto contra saldo (el servicio lo vuelve a validar bajo bloqueo).
            if (! $this->isMulti()) {
                $invoice = $this->route('invoice');
                $invoice = $invoice instanceof Invoice ? $invoice : Invoice::find($invoice);
                if ($invoice && Money::cmp($this->input('amount'), $invoice->balance()) > 0) {
                    $v->errors()->add('amount', 'El monto excede el saldo de la factura (' . Money::format($invoice->balance()) . ').');
                }
            }
        }];
    }
}

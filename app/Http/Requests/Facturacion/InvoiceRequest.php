<?php

namespace App\Http\Requests\Facturacion;

use App\Models\Organization;
use App\Models\PatientFiscalProfile;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Alta/edicion de factura (borrador). Valida captura, catalogos SAT y coherencia CFDI. */
class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permisos por middleware de ruta
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($i) => is_array($i))
            ->map(function (array $i) {
                foreach (['quantity', 'unit_price', 'discount_pct'] as $k) {
                    if (isset($i[$k]) && is_string($i[$k])) {
                        $i[$k] = preg_replace('/[^0-9.\-]/', '', $i[$k]);
                    }
                }
                if (isset($i['sat_product_key'])) {
                    $i['sat_product_key'] = preg_replace('/\D/', '', (string) $i['sat_product_key']);
                }
                if (isset($i['sat_unit_key'])) {
                    $i['sat_unit_key'] = strtoupper(trim((string) $i['sat_unit_key']));
                }
                if (($i['discount_pct'] ?? '') === '') {
                    $i['discount_pct'] = '0';
                }
                if (($i['tax_profile_id'] ?? '') === '') {
                    $i['tax_profile_id'] = null;
                }

                return $i;
            })->values()->all();

        $this->merge([
            'items' => $items,
            'doctor_member_id' => $this->input('doctor_member_id') ?: null,
            'fiscal_profile_id' => $this->input('fiscal_profile_id') ?: null,
            'quote_id' => $this->input('quote_id') ?: null,
            'receiver_mode' => $this->input('receiver_mode', 'patient'),
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $org);

        return [
            'patient_id' => ['required', 'uuid', $exists('patients')],
            'receiver_mode' => ['required', Rule::in(['patient', 'publico_general'])],
            'fiscal_profile_id' => ['nullable', 'uuid', $exists('patient_fiscal_profiles')],
            'branch_id' => ['required', 'uuid', $exists('branches')],
            'doctor_member_id' => ['nullable', 'uuid', $exists('organization_members')],
            'quote_id' => ['nullable', 'uuid', $exists('quotes')],
            'cfdi_use' => ['required', Rule::in(array_diff(array_keys(config('sat.usos_cfdi')), ['CP01', 'CN01']))],
            'cfdi_payment_method' => ['required', Rule::in(array_keys(config('sat.metodos_pago')))],
            'cfdi_payment_form' => ['required', Rule::in(array_keys(config('sat.formas_pago')))],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:300'],
            'items.*.sat_product_key' => ['required', 'regex:/^\d{8}$/'],
            'items.*.sat_unit_key' => ['required', Rule::in(array_keys(config('sat.unidades')))],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999.9999'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'items.*.tax_object_code' => ['required', Rule::in(array_keys(config('sat.objeto_impuesto')))],
            'items.*.tax_profile_id' => [
                'nullable', 'uuid', $exists('tax_profiles'),
            ],
            'items.*.treatment_id' => ['nullable', 'uuid', $exists('treatments')],
            'items.*.quote_item_id' => ['nullable', 'uuid', $exists('quote_items')],
        ];
    }

    public function attributes(): array
    {
        return [
            'patient_id' => 'paciente',
            'branch_id' => 'sucursal',
            'doctor_member_id' => 'doctor',
            'cfdi_use' => 'uso de CFDI',
            'cfdi_payment_method' => 'metodo de pago',
            'cfdi_payment_form' => 'forma de pago',
            'items' => 'conceptos',
            'items.*.description' => 'descripcion',
            'items.*.sat_product_key' => 'clave de producto/servicio',
            'items.*.sat_unit_key' => 'unidad',
            'items.*.quantity' => 'cantidad',
            'items.*.unit_price' => 'precio unitario',
            'items.*.discount_pct' => 'descuento',
            'items.*.tax_object_code' => 'objeto de impuesto',
            'items.*.tax_profile_id' => 'impuesto',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un concepto.',
            'items.min' => 'Agrega al menos un concepto.',
            'items.*.sat_product_key.regex' => 'La clave de producto/servicio debe tener 8 digitos.',
            'items.*.quantity.gt' => 'La cantidad debe ser mayor a cero.',
            'items.*.unit_price.min' => 'El precio unitario no puede ser negativo.',
            'items.*.discount_pct.between' => 'El descuento debe estar entre 0 y 100.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // PUE/PPD vs forma de pago (CFDI 4.0).
            $method = $this->input('cfdi_payment_method');
            $form = $this->input('cfdi_payment_form');
            if ($method === 'PPD' && $form !== '99') {
                $v->errors()->add('cfdi_payment_form', 'Con metodo PPD la forma de pago debe ser "99 - Por definir".');
            }
            if ($method === 'PUE' && $form === '99') {
                $v->errors()->add('cfdi_payment_form', 'Con metodo PUE indica la forma de pago real (no "99").');
            }

            // El perfil fiscal debe ser del paciente.
            if ($this->input('fiscal_profile_id')) {
                $ok = PatientFiscalProfile::where('id', $this->input('fiscal_profile_id'))
                    ->where('patient_id', $this->input('patient_id'))->exists();
                if (! $ok) {
                    $v->errors()->add('fiscal_profile_id', 'El perfil fiscal no pertenece al paciente seleccionado.');
                }
            }

            // Coherencia por partida: impuesto requerido con objeto 02/03, descuento <= importe.
            $total = '0';
            foreach ($this->input('items', []) as $i => $item) {
                $n = $i + 1;
                $objeto = $item['tax_object_code'] ?? null;
                if (in_array($objeto, ['02', '03'], true) && empty($item['tax_profile_id'])) {
                    $v->errors()->add("items.$i.tax_profile_id", "Concepto $n: selecciona el impuesto (o usa objeto de impuesto 01 / 04).");
                }
                if (in_array($objeto, ['01', '04'], true) && ! empty($item['tax_profile_id'])) {
                    $v->errors()->add("items.$i.tax_profile_id", "Concepto $n: con ese objeto de impuesto no se debe seleccionar un impuesto.");
                }
                $amount = Money::mul($item['quantity'], $item['unit_price']);
                $total = Money::add($total, $amount);
            }
            if (Money::cmp($total, '99999999999.99') > 0) {
                $v->errors()->add('items', 'El importe total excede el maximo permitido.');
            }
        }];
    }
}

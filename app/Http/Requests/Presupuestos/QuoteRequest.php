<?php

namespace App\Http\Requests\Presupuestos;

use App\Enums\RecordStatus;
use App\Models\Organization;
use App\Rules\FdiTooth;
use App\Services\Presupuestos\QuoteLookups;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de presupuestos (la autorización por permiso va en la ruta).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($row) => is_array($row))
            // Se descartan filas totalmente vacías (concepto agregado sin llenar).
            ->filter(fn ($row) => ($row['treatment_id'] ?? '') !== ''
                || trim((string) ($row['unit_price'] ?? '')) !== ''
                || ($row['tooth_fdi'] ?? '') !== '')
            ->map(function (array $row) {
                foreach (['quantity', 'unit_price', 'discount_pct'] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = str_replace(['$', ' '], '', trim((string) $row[$key]));
                    }
                }
                if (($row['discount_pct'] ?? '') === '') {
                    $row['discount_pct'] = '0';
                }
                $row['tooth_fdi'] = trim((string) ($row['tooth_fdi'] ?? ''));

                return $row;
            })
            ->values()
            ->all();

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();

        return [
            'patient_id' => ['required', 'uuid', Rule::exists('patients', 'id')
                ->where('organization_id', $org)
                ->where('status', RecordStatus::Active->value)
                ->whereNull('deleted_at')
                ->whereNull('anonymized_at')],
            'doctor_member_id' => ['required', 'uuid', Rule::exists('organization_members', 'id')
                ->where('organization_id', $org)->where('is_active', true)],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')
                ->where('organization_id', $org)->where('status', RecordStatus::Active->value)],
            'quote_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.treatment_id' => ['required', 'uuid', Rule::exists('treatments', 'id')
                ->where('organization_id', $org)->where('status', RecordStatus::Active->value)],
            'items.*.tooth_fdi' => ['nullable', new FdiTooth()],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.discount_pct' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $doctorId = $this->input('doctor_member_id');
                if ($doctorId && ! $validator->errors()->has('doctor_member_id')
                    && ! app(QuoteLookups::class)->doctors()->has($doctorId)) {
                    $validator->errors()->add('doctor_member_id', 'El usuario seleccionado no es un doctor activo.');
                }
            },
            // El total debe caber en decimal(14,2): evita un error 500 con importes extremos.
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $total = '0';
                foreach ($this->input('items', []) as $item) {
                    $total = \App\Support\Money::add($total, \App\Support\Money::mul($item['quantity'], $item['unit_price']));
                }
                if (\App\Support\Money::cmp($total, '99999999999.99') > 0) {
                    $validator->errors()->add('items', 'El importe total del presupuesto excede el máximo permitido.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Selecciona un paciente.',
            'patient_id.exists' => 'El paciente seleccionado no existe o está dado de baja.',
            'doctor_member_id.required' => 'Selecciona al doctor responsable.',
            'doctor_member_id.exists' => 'El doctor seleccionado no es válido.',
            'branch_id.required' => 'Selecciona la sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no es válida o está inactiva.',
            'quote_date.required' => 'La fecha del presupuesto es obligatoria.',
            'quote_date.date_format' => 'La fecha del presupuesto no es válida.',
            'quote_date.before_or_equal' => 'La fecha del presupuesto no puede ser mayor a un año a futuro.',
            'quote_date.after_or_equal' => 'La fecha del presupuesto no es válida.',
            'items.required' => 'Agrega al menos un concepto al presupuesto.',
            'items.min' => 'Agrega al menos un concepto al presupuesto.',
            'items.max' => 'Un presupuesto admite máximo 100 conceptos.',
            'items.*.treatment_id.required' => 'Selecciona el tratamiento en el concepto :position.',
            'items.*.treatment_id.exists' => 'El tratamiento del concepto :position no existe o está inactivo.',
            'items.*.quantity.required' => 'Indica la cantidad del concepto :position.',
            'items.*.quantity.gt' => 'La cantidad del concepto :position debe ser mayor a 0.',
            'items.*.quantity.regex' => 'La cantidad del concepto :position admite hasta 4 decimales.',
            'items.*.quantity.max' => 'La cantidad del concepto :position es demasiado grande.',
            'items.*.unit_price.required' => 'Indica el precio del concepto :position.',
            'items.*.unit_price.min' => 'El precio del concepto :position no puede ser negativo.',
            'items.*.unit_price.numeric' => 'El precio del concepto :position debe ser un número.',
            'items.*.unit_price.regex' => 'El precio del concepto :position admite hasta 4 decimales.',
            'items.*.unit_price.max' => 'El precio del concepto :position es demasiado grande.',
            'items.*.discount_pct.min' => 'El descuento del concepto :position debe estar entre 0 y 100.',
            'items.*.discount_pct.max' => 'El descuento del concepto :position debe estar entre 0 y 100.',
            'items.*.discount_pct.numeric' => 'El descuento del concepto :position debe ser un número.',
            'items.*.discount_pct.regex' => 'El descuento del concepto :position admite hasta 2 decimales.',
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.tooth_fdi' => 'pieza dental (FDI) del concepto :position',
        ];
    }
}

<?php

namespace App\Http\Requests\Tratamientos;

use App\Models\Organization;
use App\Models\TaxProfile;
use App\Rules\CfdiCatalogs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Perfil de impuestos. La tasa llega como porcentaje (16) y se guarda como
 * fracción decimal (0.160000).
 */
class TaxProfileRequest extends FormRequest
{
    /** Bolsa de errores propia: así solo se reabre el modal correspondiente. */
    protected $errorBag = 'taxProfile';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'tax_code' => trim((string) $this->input('tax_code', '002')) ?: '002',
            'rate_pct' => str_replace(['%', ' '], '', trim((string) $this->input('rate_pct', ''))),
            'is_exempt' => $this->boolean('is_exempt'),
        ]);
    }

    public function rules(): array
    {
        $current = $this->route('taxProfile');
        $currentId = $current instanceof TaxProfile ? $current->id : $current;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100',
                Rule::unique('tax_profiles', 'name')
                    ->where('organization_id', Organization::currentId())->ignore($currentId)],
            'tax_code' => ['required', 'string', 'size:3', new CfdiCatalogs('impuestos')],
            'rate_pct' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,4})?$/'],
            'is_exempt' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->boolean('is_exempt') && (float) $this->input('rate_pct') > 0
                    && ! $validator->errors()->has('rate_pct')) {
                    $validator->errors()->add('rate_pct', 'Un perfil exento debe tener tasa 0%.');
                }
            },
        ];
    }

    /** Datos listos para guardar (rate como fracción con 6 decimales). */
    public function payload(): array
    {
        return [
            'name' => $this->validated('name'),
            'tax_code' => $this->validated('tax_code'),
            'rate' => bcdiv((string) $this->validated('rate_pct'), '100', 6),
            'is_exempt' => $this->boolean('is_exempt'),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del perfil de impuestos es obligatorio.',
            'name.unique' => 'Ya existe un perfil de impuestos con ese nombre.',
            'tax_code.required' => 'Selecciona el impuesto.',
            'rate_pct.required' => 'La tasa es obligatoria (usa 0 si es exento).',
            'rate_pct.numeric' => 'La tasa debe ser un número.',
            'rate_pct.min' => 'La tasa debe estar entre 0 y 100%.',
            'rate_pct.max' => 'La tasa debe estar entre 0 y 100%.',
            'rate_pct.regex' => 'La tasa admite hasta 4 decimales.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'tax_code' => 'impuesto', 'rate_pct' => 'tasa'];
    }
}

<?php

namespace App\Http\Requests\Tratamientos;

use App\Enums\RecordStatus;
use App\Models\Organization;
use App\Models\Treatment;
use App\Rules\CfdiCatalogs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta/edición de un tratamiento del catálogo. La categoría llega como texto
 * (se crea si no existe) o, alternativamente, como category_id.
 */
class TreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $duration = preg_replace('/\s*min(utos)?\.?\s*$/i', '', trim((string) $this->input('duration_minutes', '')));
        $price = str_replace(['$', ' '], '', trim((string) $this->input('price', '')));

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'category_name' => trim((string) $this->input('category_name', '')),
            'duration_minutes' => $duration,
            'price' => $price,
            'sat_product_key' => trim((string) $this->input('sat_product_key', '')),
            'sat_unit_key' => strtoupper(trim((string) $this->input('sat_unit_key', ''))) ?: 'E48',
            'tax_profile_id' => $this->input('tax_profile_id') ?: null,
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();
        $current = $this->route('treatment');
        $currentId = $current instanceof Treatment ? $current->id : $current;

        return [
            'name' => ['required', 'string', 'min:3', 'max:200',
                Rule::unique('treatments', 'name')->where('organization_id', $org)->ignore($currentId)],
            'category_name' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid', Rule::exists('treatment_categories', 'id')->where('organization_id', $org)],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'sat_product_key' => ['required', 'digits:8'],
            'sat_unit_key' => ['required', 'string', 'max:3', new CfdiCatalogs('unidades')],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_profile_id' => ['nullable', 'uuid', Rule::exists('tax_profiles', 'id')
                ->where('organization_id', $org)->where('status', RecordStatus::Active->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del tratamiento es obligatorio.',
            'name.min' => 'El nombre del tratamiento debe tener al menos 3 caracteres.',
            'name.unique' => 'Ya existe un tratamiento con ese nombre.',
            'duration_minutes.required' => 'Indica la duración del tratamiento en minutos.',
            'duration_minutes.integer' => 'La duración debe ser un número entero de minutos (ej. 30).',
            'duration_minutes.min' => 'La duración debe ser de al menos 1 minuto.',
            'duration_minutes.max' => 'La duración no puede exceder 1440 minutos (24 h).',
            'sat_product_key.required' => 'La clave SAT de producto o servicio es obligatoria.',
            'sat_product_key.digits' => 'La clave SAT de producto o servicio debe tener 8 dígitos (ej. 85121800).',
            'sat_unit_key.required' => 'La clave de unidad SAT es obligatoria.',
            'price.required' => 'El precio es obligatorio.',
            'price.numeric' => 'El precio debe ser un número.',
            'price.min' => 'El precio no puede ser negativo.',
            'price.max' => 'El precio es demasiado grande.',
            'price.regex' => 'El precio admite hasta 4 decimales y no debe llevar comas.',
            'tax_profile_id.exists' => 'El perfil de impuestos no existe o está inactivo.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'category_name' => 'categoría',
            'duration_minutes' => 'duración',
            'sat_product_key' => 'clave SAT',
            'sat_unit_key' => 'unidad SAT',
            'price' => 'precio',
        ];
    }
}

<?php

namespace App\Http\Requests\Tratamientos;

use App\Models\Organization;
use App\Models\TreatmentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TreatmentCategoryRequest extends FormRequest
{
    /** Bolsa de errores propia: así solo se reabre el modal correspondiente. */
    protected $errorBag = 'category';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name', ''))]);
    }

    public function rules(): array
    {
        $current = $this->route('category');
        $currentId = $current instanceof TreatmentCategory ? $current->id : $current;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100',
                Rule::unique('treatment_categories', 'name')
                    ->where('organization_id', Organization::currentId())->ignore($currentId)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.min' => 'El nombre de la categoría debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre de la categoría no puede exceder 100 caracteres.',
            'name.unique' => 'Ya existe una categoría con ese nombre.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre de la categoría'];
    }
}

<?php

namespace App\Http\Requests\Recetas;

use App\Models\Medication;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición del catálogo propio de medicamentos. */
class MedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;

        $this->merge([
            'name' => $clean($this->input('name')),
            'active_ingredient' => $clean($this->input('active_ingredient')),
            'strength' => $clean($this->input('strength')),
            'presentation' => $clean($this->input('presentation')),
        ]);
    }

    public function rules(): array
    {
        $current = $this->route('medication');
        $currentId = $current instanceof Medication ? $current->id : null;

        return [
            'name' => [
                'required', 'string', 'max:200',
                function (string $attribute, mixed $value, \Closure $fail) use ($currentId) {
                    $exists = Medication::query()
                        ->where('name', $value)
                        ->where(fn ($q) => $this->input('strength') === null ? $q->whereNull('strength') : $q->where('strength', $this->input('strength')))
                        ->where(fn ($q) => $this->input('presentation') === null ? $q->whereNull('presentation') : $q->where('presentation', $this->input('presentation')))
                        ->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))
                        ->exists();
                    if ($exists) {
                        $fail('Ya existe un medicamento con el mismo nombre, concentración y presentación.');
                    }
                },
            ],
            'active_ingredient' => ['nullable', 'string', 'max:200'],
            'strength' => ['nullable', 'string', 'max:80'],
            'presentation' => ['nullable', 'string', 'max:120'],
            'status' => [$this->isMethod('POST') ? 'nullable' : 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del medicamento es obligatorio.',
            'name.max' => 'El nombre no puede superar los 200 caracteres.',
            'active_ingredient.max' => 'El principio activo no puede superar los 200 caracteres.',
            'strength.max' => 'La concentración no puede superar los 80 caracteres.',
            'presentation.max' => 'La presentación no puede superar los 120 caracteres.',
            'status.required' => 'Seleccione el estado del medicamento.',
            'status.in' => 'El estado seleccionado no es válido.',
        ];
    }

    /** Campos normalizados para guardar; organization_id siempre es el consultorio. */
    public function payload(): array
    {
        $data = $this->validated();
        $data['status'] = $data['status'] ?? 'active';
        $data['organization_id'] = Organization::currentId();

        return $data;
    }
}

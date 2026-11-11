<?php

namespace App\Http\Requests\Usuarios;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta de rol personalizado (modal "Nuevo rol"). */
class StoreRoleRequest extends FormRequest
{
    protected $errorBag = 'role';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name'))),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ]);
    }

    /** Rol que se edita (null al crear). */
    protected function ignoredRoleId(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:3', 'max:100',
                Rule::unique('roles', 'name')
                    ->where('organization_id', Organization::currentId())
                    ->ignore($this->ignoredRoleId()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del rol.',
            'name.min' => 'El nombre del rol debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre del rol no puede superar 100 caracteres.',
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'description.max' => 'La descripción no puede superar 255 caracteres.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'description' => 'descripción'];
    }
}

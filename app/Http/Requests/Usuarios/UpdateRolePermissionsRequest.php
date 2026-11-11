<?php

namespace App\Http\Requests\Usuarios;

use Illuminate\Foundation\Http\FormRequest;

/** Matriz de permisos: lista de ids de permissions marcados. */
class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => array_values(array_filter((array) $this->input('permissions', [])))]);
    }

    public function rules(): array
    {
        return [
            'permissions' => ['array'],
            'permissions.*' => ['uuid', 'distinct', 'exists:permissions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.uuid' => 'Se recibió un permiso con formato inválido.',
            'permissions.*.exists' => 'Se recibió un permiso que no existe.',
            'permissions.*.distinct' => 'Hay permisos repetidos en el envío.',
        ];
    }
}

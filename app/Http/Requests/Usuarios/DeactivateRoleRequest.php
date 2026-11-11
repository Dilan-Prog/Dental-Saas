<?php

namespace App\Http\Requests\Usuarios;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Desactivar rol: opcionalmente, rol al que se reasignan sus usuarios. */
class DeactivateRoleRequest extends FormRequest
{
    protected $errorBag = 'role_deactivate';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'replacement_role_id' => [
                'nullable', 'uuid',
                Rule::exists('roles', 'id')
                    ->where('organization_id', Organization::currentId())
                    ->where('status', 'active')
                    ->whereNot('id', $this->route('role')?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'replacement_role_id.exists' => 'El rol de reemplazo no existe, está inactivo o es el mismo rol.',
        ];
    }
}

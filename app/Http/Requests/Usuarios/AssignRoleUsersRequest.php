<?php

namespace App\Http\Requests\Usuarios;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Asignar usuarios (miembros) a un rol. */
class AssignRoleUsersRequest extends FormRequest
{
    protected $errorBag = 'role_assign';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('organization_members', 'id')->where('organization_id', Organization::currentId()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'member_ids.required' => 'Selecciona al menos un usuario.',
            'member_ids.min' => 'Selecciona al menos un usuario.',
            'member_ids.*.exists' => 'Alguno de los usuarios seleccionados no existe.',
        ];
    }
}

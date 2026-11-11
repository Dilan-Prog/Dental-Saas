<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Branch;
use App\Models\Organization;
use App\Rules\MxPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key) => ($v = trim((string) $this->input($key))) === '' ? null : $v;
        $phone = $clean('phone');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'address_line' => $clean('address_line'),
            'phone' => $phone === null ? null : (MxPhone::normalize($phone) ?? $phone),
            'manager_user_id' => $clean('manager_user_id'),
            'is_main' => $this->boolean('is_main'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = Organization::currentId();
        /** @var Branch|null $branch */
        $branch = $this->route('branch');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:150',
                Rule::unique('branches', 'name')->where('organization_id', $orgId)->ignore($branch?->id),
            ],
            'address_line' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', new MxPhone()],
            'manager_user_id' => [
                'nullable', 'uuid',
                Rule::exists('organization_members', 'user_id')->where('organization_id', $orgId)->where('is_active', 1),
            ],
            'is_main' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la sucursal es obligatorio.',
            'name.unique' => 'Ya existe una sucursal con ese nombre.',
            'manager_user_id.exists' => 'El responsable debe ser un usuario activo de la clínica.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'address_line' => 'dirección',
            'phone' => 'teléfono',
            'manager_user_id' => 'responsable',
        ];
    }
}

<?php

namespace App\Http\Requests\Usuarios;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas comunes de alta/edición de usuario. Las subclases definen el error bag
 * y el usuario a ignorar en la regla de correo único.
 */
abstract class UsuarioRequest extends FormRequest
{
    /** Id del usuario que se edita (null al crear). */
    protected function ignoredUserId(): ?string
    {
        return null;
    }

    public function authorize(): bool
    {
        return true; // la autorización se resuelve con el middleware permission:...
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('full_name'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'professional_license' => $this->filled('professional_license') ? trim((string) $this->input('professional_license')) : null,
            'specialty' => $this->filled('specialty') ? preg_replace('/\s+/u', ' ', trim((string) $this->input('specialty'))) : null,
            'is_doctor' => $this->boolean('is_doctor'),
            'branch_ids' => array_values(array_filter((array) $this->input('branch_ids', []))),
        ]);
    }

    public function rules(): array
    {
        $org = Organization::currentId();

        return [
            'full_name' => ['required', 'string', 'min:3', 'max:200', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'’-]*$/u"],
            'email' => [
                'required', 'string', 'max:254', 'email:rfc',
                Rule::unique('users', 'email')->ignore($this->ignoredUserId()),
            ],
            'role_id' => [
                'required', 'uuid',
                Rule::exists('roles', 'id')->where('organization_id', $org)->where('status', 'active'),
            ],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('branches', 'id')->where('organization_id', $org)->where('status', 'active'),
            ],
            'is_doctor' => ['boolean'],
            'professional_license' => [
                Rule::requiredIf(fn () => $this->boolean('is_doctor')),
                'nullable', 'regex:/^\d{7,8}$/',
            ],
            'specialty' => ['nullable', 'string', 'max:120'],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Escribe el nombre completo.',
            'full_name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'full_name.regex' => 'El nombre solo puede contener letras, espacios, puntos, apóstrofos y guiones.',
            'email.required' => 'Escribe el correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Ya existe un usuario con ese correo electrónico.',
            'role_id.required' => 'Selecciona un rol.',
            'role_id.exists' => 'El rol seleccionado no existe o está inactivo.',
            'branch_ids.required' => 'Asigna al menos una sucursal.',
            'branch_ids.min' => 'Asigna al menos una sucursal.',
            'branch_ids.*.exists' => 'Una de las sucursales seleccionadas no existe o está inactiva.',
            'professional_license.required' => 'La cédula profesional es obligatoria para un perfil de doctor.',
            'professional_license.regex' => 'La cédula profesional debe tener 7 u 8 dígitos.',
            'specialty.max' => 'La especialidad no puede superar 120 caracteres.',
            'signature.image' => 'La firma debe ser una imagen.',
            'signature.mimes' => 'La imagen de firma debe ser PNG o JPG.',
            'signature.max' => 'La imagen de firma no puede pesar más de 1 MB.',
            'signature.dimensions' => 'La imagen de firma no puede superar 2000 × 1000 píxeles.',
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'nombre',
            'email' => 'correo',
            'role_id' => 'rol',
            'branch_ids' => 'sucursales',
            'professional_license' => 'cédula profesional',
            'specialty' => 'especialidad',
            'signature' => 'firma',
        ];
    }
}

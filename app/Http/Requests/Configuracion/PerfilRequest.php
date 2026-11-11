<?php

namespace App\Http\Requests\Configuracion;

use App\Services\Configuracion\StoredImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class PerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key) => ($v = trim((string) $this->input($key))) === '' ? null : $v;

        $this->merge([
            'full_name' => preg_replace('/\s+/', ' ', trim((string) $this->input('full_name'))),
            'email' => strtolower(trim((string) $this->input('email'))),
            'professional_license' => $clean('professional_license'),
            'specialty' => $clean('specialty'),
            'remove_avatar' => $this->boolean('remove_avatar'),
            'remove_signature' => $this->boolean('remove_signature'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $image = fn (array $allowed, int $kb, ?int $side) => [
            'nullable', 'file',
            function (string $attribute, mixed $value, \Closure $fail) use ($allowed, $kb, $side) {
                if ($value instanceof UploadedFile && ($error = StoredImage::validate($value, $allowed, $kb, $side))) {
                    $fail($error);
                }
            },
        ];

        return [
            'full_name' => ['required', 'string', 'min:3', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'avatar' => $image(['png', 'jpg', 'jpeg', 'webp'], 2048, 2000),
            'signature' => $image(['png'], 1024, 1600),
            'professional_license' => ['nullable', 'string', 'regex:/^[0-9]{7,8}$/'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'remove_avatar' => ['boolean'],
            'remove_signature' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Tu nombre es obligatorio.',
            'full_name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ese correo ya está registrado por otro usuario.',
            'professional_license.regex' => 'La cédula profesional debe tener 7 u 8 dígitos.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'full_name' => 'nombre',
            'email' => 'correo',
            'avatar' => 'foto',
            'signature' => 'firma digital',
            'professional_license' => 'cédula profesional',
            'specialty' => 'especialidad',
        ];
    }
}

<?php

namespace App\Http\Requests\Configuracion;

use App\Rules\MxPhone;
use App\Rules\PostalCodeMx;
use App\Rules\Rfc;
use App\Services\Configuracion\ConfiguracionCatalog;
use App\Services\Configuracion\StoredImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ClinicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = trim((string) $this->input('phone'));
        $rfc = strtoupper(preg_replace('/\s+/', '', (string) $this->input('rfc')));

        $this->merge([
            'commercial_name' => trim((string) $this->input('commercial_name')),
            'rfc' => $rfc === '' ? null : $rfc,
            'phone' => $phone === '' ? null : (MxPhone::normalize($phone) ?? $phone),
            'email' => ($e = trim((string) $this->input('email'))) === '' ? null : strtolower($e),
            'address_line' => ($a = trim((string) $this->input('address_line'))) === '' ? null : $a,
            'address_postal_code' => ($p = trim((string) $this->input('address_postal_code'))) === '' ? null : $p,
            'remove_logo' => $this->boolean('remove_logo'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'commercial_name' => ['required', 'string', 'min:2', 'max:200'],
            'rfc' => ['nullable', 'string', 'max:13', new Rfc(null, false)],
            'phone' => ['nullable', 'string', new MxPhone()],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'address_line' => ['nullable', 'string', 'max:300'],
            'address_postal_code' => ['nullable', 'string', new PostalCodeMx()],
            'timezone' => ['required', 'string', Rule::in(array_keys(ConfiguracionCatalog::TIMEZONES))],
            'currency' => ['required', 'string', Rule::in(array_keys(ConfiguracionCatalog::CURRENCIES))],
            'logo' => [
                'nullable',
                'file',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value instanceof UploadedFile
                        && ($error = StoredImage::validate($value, ['png', 'jpg', 'jpeg', 'webp', 'svg'], 2048, 2000))) {
                        $fail($error);
                    }
                },
            ],
            'remove_logo' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'commercial_name.required' => 'El nombre comercial es obligatorio.',
            'timezone.in' => 'Selecciona una zona horaria válida de México.',
            'currency.in' => 'La moneda seleccionada no está disponible.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'commercial_name' => 'nombre comercial',
            'rfc' => 'RFC',
            'phone' => 'teléfono',
            'email' => 'correo electrónico',
            'address_line' => 'dirección',
            'address_postal_code' => 'código postal',
            'timezone' => 'zona horaria',
            'currency' => 'moneda',
            'logo' => 'logotipo',
        ];
    }
}

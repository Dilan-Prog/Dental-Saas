<?php

namespace App\Http\Requests\Configuracion;

use App\Rules\CfdiCatalogs;
use App\Rules\PostalCodeMx;
use App\Rules\Rfc;
use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FacturacionRequest extends FormRequest
{
    private const REF_REGEX = '/^[A-Za-z0-9][A-Za-z0-9_\-\/\.:@#]*$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (string $key) => ($v = trim((string) $this->input($key))) === '' ? null : $v;

        $rfc = strtoupper(preg_replace('/\s+/', '', (string) $this->input('rfc')));

        $this->merge([
            'legal_name' => $clean('legal_name'),
            'rfc' => $rfc === '' ? null : $rfc,
            'tax_regime_code' => $clean('tax_regime_code'),
            'fiscal_postal_code' => $clean('fiscal_postal_code'),
            'pac_provider' => $clean('pac_provider'),
            'default_series' => ($s = strtoupper(trim((string) $this->input('default_series')))) === '' ? null : $s,
            'csd_certificate_secret_ref' => $clean('csd_certificate_secret_ref'),
            'csd_key_secret_ref' => $clean('csd_key_secret_ref'),
            'pac_credentials_secret_ref' => $clean('pac_credentials_secret_ref'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ref = ['nullable', 'string', 'max:255', 'regex:'.self::REF_REGEX];

        return [
            'legal_name' => ['nullable', 'string', 'min:3', 'max:300'],
            'rfc' => ['nullable', 'string', 'max:13', new Rfc(null, false)],
            'tax_regime_code' => ['nullable', 'string', new CfdiCatalogs('regimenes')],
            'fiscal_postal_code' => ['nullable', 'string', new PostalCodeMx()],
            'pac_provider' => ['nullable', 'string', Rule::in(array_keys(ConfiguracionCatalog::PAC_PROVIDERS))],
            'default_series' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
            'csd_certificate_secret_ref' => $ref,
            'csd_key_secret_ref' => $ref,
            'pac_credentials_secret_ref' => $ref,
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();

                if (! $errors->has('rfc') && ! $errors->has('tax_regime_code')
                    && ! ConfiguracionCatalog::regimeMatchesRfc($this->input('rfc'), $this->input('tax_regime_code'))) {
                    $errors->add('tax_regime_code', 'El régimen '.$this->input('tax_regime_code').' no corresponde con el tipo de RFC capturado (persona '
                        .(strlen((string) $this->input('rfc')) === 12 ? 'moral' : 'física').').');
                }

                // Para timbrar con CSD se requieren los datos del emisor completos.
                $usesCsd = $this->filled('csd_certificate_secret_ref') || $this->filled('csd_key_secret_ref');

                if ($usesCsd || $this->filled('pac_provider')) {
                    foreach (['legal_name' => 'la razón social', 'rfc' => 'el RFC', 'tax_regime_code' => 'el régimen fiscal', 'fiscal_postal_code' => 'el código postal fiscal'] as $field => $label) {
                        if (! $this->filled($field) && ! $errors->has($field)) {
                            $errors->add($field, "Captura {$label} para configurar el timbrado.");
                        }
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $refMsg = 'Ingresa solo la referencia al gestor de secretos (p. ej. vault://clinica/csd-cer). Nunca pegues certificados, llaves ni contraseñas.';

        return [
            'csd_certificate_secret_ref.regex' => $refMsg,
            'csd_key_secret_ref.regex' => $refMsg,
            'pac_credentials_secret_ref.regex' => $refMsg,
            'default_series.regex' => 'La serie debe tener de 1 a 10 caracteres alfanuméricos en mayúsculas.',
            'pac_provider.in' => 'Selecciona un proveedor de timbrado de la lista.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'legal_name' => 'razón social',
            'rfc' => 'RFC',
            'tax_regime_code' => 'régimen fiscal',
            'fiscal_postal_code' => 'código postal fiscal',
            'pac_provider' => 'proveedor de timbrado',
            'default_series' => 'serie',
            'csd_certificate_secret_ref' => 'referencia del certificado (CSD)',
            'csd_key_secret_ref' => 'referencia de la llave (CSD)',
            'pac_credentials_secret_ref' => 'referencia de credenciales del PAC',
        ];
    }
}

<?php

namespace App\Http\Requests\Pacientes\Concerns;

use App\Enums\SexType;
use App\Models\Patient;
use App\Rules\CfdiCatalogs;
use App\Rules\MxPhone;
use App\Rules\PostalCodeMx;
use App\Rules\Rfc;
use App\Services\Pacientes\FiscalCoherence;
use App\Services\Pacientes\PatientService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Reglas y normalización compartidas por el alta y la edición de pacientes.
 */
trait ValidatesPatientData
{
    /** Nombre: letras (con acentos), espacios, punto, apóstrofo y guion. */
    private const NAME_REGEX = "/^[\\pL\\pM][\\pL\\pM\\s.'\\-]*$/u";

    protected function normalizePatientInput(): void
    {
        $merge = [];

        if ($this->has('full_name')) {
            $merge['full_name'] = preg_replace('/\s+/u', ' ', trim((string) $this->input('full_name')));
        }
        if ($this->filled('phone')) {
            $raw = (string) $this->input('phone');
            $merge['phone'] = MxPhone::normalize($raw) ?? $raw;
        }
        if ($this->filled('email')) {
            $merge['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        if (is_array($fiscal = $this->input('fiscal'))) {
            if (! empty($fiscal['rfc'])) {
                $fiscal['rfc'] = strtoupper(preg_replace('/\s+/', '', (string) $fiscal['rfc']));
            }
            if (! empty($fiscal['fiscal_postal_code'])) {
                $fiscal['fiscal_postal_code'] = preg_replace('/\D+/', '', (string) $fiscal['fiscal_postal_code']);
            }
            $merge['fiscal'] = $fiscal;
        }

        if (is_array($guardian = $this->input('guardian'))) {
            if (! empty($guardian['phone'])) {
                $guardian['phone'] = MxPhone::normalize((string) $guardian['phone']) ?? $guardian['phone'];
            }
            if (! empty($guardian['email'])) {
                $guardian['email'] = mb_strtolower(trim((string) $guardian['email']));
            }
            $merge['guardian'] = $guardian;
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    protected function personalRules(): array
    {
        $doctorIds = array_keys(app(PatientService::class)->doctorOptions());

        return [
            'full_name' => ['required', 'string', 'min:3', 'max:250', 'regex:'.self::NAME_REGEX],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'sex' => ['nullable', Rule::in(SexType::values())],
            'phone' => ['required', 'string', new MxPhone()],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'assigned_doctor_member_id' => ['nullable', 'uuid', Rule::in($doctorIds)],
            'ignore_duplicates' => ['nullable', 'boolean'],
        ];
    }

    /** Datos fiscales opcionales: si se captura cualquiera, se exigen RFC, razón social, régimen y CP. */
    protected function fiscalRules(): array
    {
        $group = ['fiscal.rfc', 'fiscal.legal_name', 'fiscal.tax_regime_code', 'fiscal.fiscal_postal_code', 'fiscal.default_cfdi_use'];
        $with = fn (string $self) => 'required_with:'.implode(',', array_diff($group, [$self]));

        return [
            'fiscal' => ['nullable', 'array'],
            'fiscal.rfc' => ['nullable', 'string', 'max:13', new Rfc(), $with('fiscal.rfc')],
            'fiscal.legal_name' => ['nullable', 'string', 'max:300', $with('fiscal.legal_name')],
            'fiscal.tax_regime_code' => ['nullable', 'string', new CfdiCatalogs('regimenes'), $with('fiscal.tax_regime_code')],
            'fiscal.fiscal_postal_code' => ['nullable', 'string', new PostalCodeMx(), $with('fiscal.fiscal_postal_code')],
            'fiscal.default_cfdi_use' => ['nullable', 'string', new CfdiCatalogs('usos_cfdi')],
        ];
    }

    /** Tutor: obligatorio cuando el paciente es menor de edad y todavía no tiene uno. */
    protected function guardianRules(bool $required): array
    {
        return [
            'guardian' => ['nullable', 'array'],
            'guardian.full_name' => [Rule::requiredIf(fn () => $required || $this->filled('guardian.phone') || $this->filled('guardian.email')), 'nullable', 'string', 'min:3', 'max:250', 'regex:'.self::NAME_REGEX],
            'guardian.relationship' => ['nullable', 'string', Rule::in(PatientService::RELATIONSHIPS)],
            'guardian.phone' => [Rule::requiredIf(fn () => $required && ! $this->input('guardian.email')), 'nullable', 'string', new MxPhone()],
            'guardian.email' => ['nullable', 'string', 'email:rfc', 'max:254'],
        ];
    }

    public function isMinorInput(): bool
    {
        $birth = $this->input('birth_date');
        if (! $birth) {
            return false;
        }
        try {
            return app(PatientService::class)->isMinor(Carbon::createFromFormat('Y-m-d', (string) $birth)->startOfDay());
        } catch (\Throwable) {
            return false;
        }
    }

    /** Coherencia RFC/régimen y advertencia de posibles duplicados. */
    protected function afterPatientValidation(Validator $validator, ?Patient $ignore = null): void
    {
        $validator->after(function (Validator $v) use ($ignore) {
            $err = FiscalCoherence::check($this->input('fiscal.rfc'), $this->input('fiscal.tax_regime_code'));
            if ($err && ! $v->errors()->has('fiscal.tax_regime_code')) {
                $v->errors()->add('fiscal.tax_regime_code', $err);
            }

            if ($v->errors()->isNotEmpty() || $this->boolean('ignore_duplicates')) {
                return;
            }

            $dupes = app(PatientService::class)->findDuplicates($this->only('full_name', 'birth_date', 'phone', 'email'), $ignore);
            if ($dupes->isNotEmpty()) {
                $list = $dupes->map(fn ($p) => $p->folio.' · '.$p->full_name)->implode('; ');
                $v->errors()->add('duplicate', 'Posible paciente duplicado (mismo teléfono, correo o nombre y fecha de nacimiento): '.$list.'. Marca la casilla para registrarlo de todos modos.');
            }
        });
    }

    /** @return array<string, string> */
    protected function patientMessages(): array
    {
        return [
            'full_name.required' => 'El nombre completo es obligatorio.',
            'full_name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'full_name.regex' => 'El nombre solo puede contener letras, espacios, punto, apóstrofo y guion.',
            'birth_date.required' => 'La fecha de nacimiento es obligatoria.',
            'birth_date.date_format' => 'La fecha de nacimiento no es válida.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'birth_date.after_or_equal' => 'La fecha de nacimiento no es válida (anterior a 1900).',
            'phone.required' => 'El teléfono es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'assigned_doctor_member_id.in' => 'El doctor asignado debe ser un doctor activo del consultorio.',
            'fiscal.rfc.required_with' => 'Captura el RFC para guardar los datos fiscales.',
            'fiscal.legal_name.required_with' => 'Captura la razón social para guardar los datos fiscales.',
            'fiscal.tax_regime_code.required_with' => 'Selecciona el régimen fiscal.',
            'fiscal.fiscal_postal_code.required_with' => 'Captura el código postal fiscal.',
            'guardian.full_name.required' => 'El paciente es menor de edad: captura el nombre del tutor.',
            'guardian.phone.required' => 'Captura el teléfono o el correo del tutor.',
        ];
    }

    /** @return array<string, string> */
    protected function patientAttributes(): array
    {
        return [
            'full_name' => 'nombre completo',
            'birth_date' => 'fecha de nacimiento',
            'phone' => 'teléfono',
            'email' => 'correo',
            'sex' => 'sexo',
            'fiscal.rfc' => 'RFC',
            'fiscal.legal_name' => 'razón social',
            'fiscal.tax_regime_code' => 'régimen fiscal',
            'fiscal.fiscal_postal_code' => 'código postal fiscal',
            'fiscal.default_cfdi_use' => 'uso de CFDI',
            'guardian.full_name' => 'nombre del tutor',
            'guardian.phone' => 'teléfono del tutor',
            'guardian.email' => 'correo del tutor',
            'guardian.relationship' => 'parentesco',
        ];
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CURP de 18 caracteres. Úsala con 'nullable' cuando sea opcional. */
class Curp implements ValidationRule
{
    private const PATRON = '/^[A-Z][AEIOUX][A-Z]{2}[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9][0-9]$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match(self::PATRON, strtoupper(trim((string) $value)))) {
            $fail('El campo :attribute no tiene un formato de CURP válido.');
        }
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Código postal mexicano: 5 dígitos (rango 01000-99999). */
class PostalCodeMx implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^(0[1-9][0-9]{3}|[1-9][0-9]{4})$/', (string) $value)) {
            $fail('El campo :attribute debe ser un código postal de 5 dígitos.');
        }
    }
}

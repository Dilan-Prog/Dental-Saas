<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Teléfono mexicano de 10 dígitos (acepta espacios, guiones, paréntesis y +52 / 52). */
class MxPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize((string) $value) === null) {
            $fail('El campo :attribute debe ser un teléfono de 10 dígitos.');
        }
    }

    /** Devuelve los 10 dígitos o null si no es válido. */
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = substr($digits, 3);
        }

        return preg_match('/^[2-9][0-9]{9}$/', $digits) ? $digits : null;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numeración FDI: permanentes 11-18, 21-28, 31-38, 41-48;
 * temporales 51-55, 61-65, 71-75, 81-85.
 * $dentition: null, 'permanente' o 'temporal'.
 */
class FdiTooth implements ValidationRule
{
    public function __construct(private ?string $dentition = null)
    {
    }

    public static function permanent(): array
    {
        return array_merge(range(11, 18), range(21, 28), range(31, 38), range(41, 48));
    }

    public static function temporary(): array
    {
        return array_merge(range(51, 55), range(61, 65), range(71, 75), range(81, 85));
    }

    public static function dentitionOf(int $tooth): ?string
    {
        return in_array($tooth, self::permanent(), true) ? 'permanente'
            : (in_array($tooth, self::temporary(), true) ? 'temporal' : null);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || (string) (int) $value !== (string) $value) {
            $fail('El campo :attribute debe ser un número de diente FDI válido.');
            return;
        }

        $dent = self::dentitionOf((int) $value);

        if ($dent === null || ($this->dentition !== null && $dent !== $this->dentition)) {
            $fail('El campo :attribute debe ser un número de diente FDI válido'
                . ($this->dentition ? " ({$this->dentition})." : '.'));
        }
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RFC mexicano: persona física (13), moral (12) o genéricos del SAT
 * (XAXX010101000 público en general nacional, XEXX010101000 extranjero).
 * $tipo: null (ambos), 'fisica' o 'moral'.
 */
class Rfc implements ValidationRule
{
    public const GENERICOS = ['XAXX010101000', 'XEXX010101000'];

    public function __construct(private ?string $tipo = null, private bool $permitirGenericos = true)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value, $this->tipo, $this->permitirGenericos)) {
            $fail('El campo :attribute no tiene un formato de RFC válido.');
        }
    }

    public static function isValid(string $rfc, ?string $tipo = null, bool $permitirGenericos = true): bool
    {
        $rfc = strtoupper(trim($rfc));

        if (in_array($rfc, self::GENERICOS, true)) {
            return $permitirGenericos && $tipo !== 'moral';
        }

        $fecha = '[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])';
        $homo = '[A-Z0-9]{3}';
        $fisica = "/^[A-ZÑ&]{4}{$fecha}{$homo}$/u";
        $moral = "/^[A-ZÑ&]{3}{$fecha}{$homo}$/u";

        return match ($tipo) {
            'fisica' => (bool) preg_match($fisica, $rfc),
            'moral' => (bool) preg_match($moral, $rfc),
            default => (bool) (preg_match($fisica, $rfc) || preg_match($moral, $rfc)),
        };
    }
}

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

        $esFisica = (bool) preg_match($fisica, $rfc);
        $esMoral = (bool) preg_match($moral, $rfc);

        $valido = match ($tipo) {
            'fisica' => $esFisica,
            'moral' => $esMoral,
            default => $esFisica || $esMoral,
        };

        return $valido && self::fechaPosible($rfc, $esFisica ? 4 : 3);
    }

    /** La fecha AAMMDD embebida debe existir en el calendario (29 de febrero permitido: el siglo no se conoce). */
    private static function fechaPosible(string $rfc, int $offset): bool
    {
        $mes = (int) substr($rfc, $offset + 2, 2);
        $dia = (int) substr($rfc, $offset + 4, 2);
        $max = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

        return isset($max[$mes]) && $dia >= 1 && $dia <= $max[$mes];
    }
}

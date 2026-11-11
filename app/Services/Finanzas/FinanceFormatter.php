<?php

namespace App\Services\Finanzas;

use App\Support\Money;
use Carbon\CarbonInterface;

/** Formatos en espanol para el modulo (sin depender del locale del servidor). */
class FinanceFormatter
{
    public const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    public const MONTHS_LONG = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** 6 jun 2026 */
    public static function date(?CarbonInterface $date): string
    {
        if (! $date) {
            return '—';
        }

        return $date->day.' '.self::MONTHS_SHORT[$date->month - 1].' '.$date->year;
    }

    /** 6 jun 2026 14:30 */
    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? self::date($date).' '.$date->format('H:i') : '—';
    }

    /** junio 2026 */
    public static function month(CarbonInterface $date): string
    {
        return self::MONTHS_LONG[$date->month - 1].' '.$date->year;
    }

    /** Ene, Feb... */
    public static function monthShort(CarbonInterface $date): string
    {
        return ucfirst(self::MONTHS_SHORT[$date->month - 1]);
    }

    /** +$6,000.00 / −$1,240.00 (el signo depende de la direccion). */
    public static function signed(string $amount, bool $isIncome): string
    {
        return ($isIncome ? '+' : '−').Money::format($amount);
    }
}

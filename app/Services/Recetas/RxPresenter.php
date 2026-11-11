<?php

namespace App\Services\Recetas;

use App\Models\Patient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Formato de fechas, edades y colores de avatar para las vistas de recetas.
 */
class RxPresenter
{
    private const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** 28 may 2026 */
    public static function date(?CarbonInterface $date): string
    {
        if (! $date) {
            return '—';
        }

        return $date->day.' '.self::MONTHS_SHORT[$date->month - 1].' '.$date->year;
    }

    /** septiembre 2026 */
    public static function monthYear(?CarbonInterface $date = null): string
    {
        $date ??= Carbon::now();

        return self::MONTHS[$date->month - 1].' '.$date->year;
    }

    /** Edad del paciente a la fecha de la receta, p. ej. "34 años". */
    public static function age(?Patient $patient, ?CarbonInterface $on = null): string
    {
        if (! $patient || ! $patient->birth_date) {
            return '—';
        }
        $years = (int) $patient->birth_date->diffInYears($on ?? Carbon::now());

        return $years === 1 ? '1 año' : $years.' años';
    }

    /** Clase CSS de color de avatar estable por nombre (av-0 … av-5). */
    public static function avatarClass(string $name): string
    {
        return 'rx-av-'.(abs(crc32($name)) % 6);
    }

    public static function medsCount(int $n): string
    {
        return $n === 1 ? '1 medicamento' : $n.' medicamentos';
    }
}

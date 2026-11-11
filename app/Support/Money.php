<?php

namespace App\Support;

/**
 * Aritmética monetaria con bcmath (cadenas decimales) y redondeo half-up
 * a 2 decimales por concepto. Importes: 2 decimales; precios/cantidades: 4.
 */
class Money
{
    /** Redondea half-up (away from zero) a $scale decimales. */
    public static function round(string|int|float|null $value, int $scale = 2): string
    {
        $value = self::norm($value);
        $half = '0.' . str_repeat('0', $scale) . '5';

        return str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b, int $scale = 2): string
    {
        return bcadd(self::norm($a), self::norm($b), $scale);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b, int $scale = 2): string
    {
        return bcsub(self::norm($a), self::norm($b), $scale);
    }

    /** Multiplica con precisión intermedia de 8 decimales y redondea a $scale. */
    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = 2): string
    {
        return self::round(bcmul(self::norm($a), self::norm($b), 8), $scale);
    }

    /** Porcentaje (0-100) de un importe: $amount * $pct / 100. */
    public static function percent(string|int|float|null $amount, string|int|float|null $pct, int $scale = 2): string
    {
        return self::round(bcdiv(bcmul(self::norm($amount), self::norm($pct), 8), '100', 8), $scale);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b, int $scale = 2): int
    {
        return bccomp(self::norm($a), self::norm($b), $scale);
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = '0';
        foreach ($values as $v) {
            $total = bcadd($total, self::norm($v), 8);
        }

        return self::round($total, $scale);
    }

    /** $1,234.50 (MXN por defecto). */
    public static function format(string|int|float|null $value, string $currency = 'MXN', bool $withCode = false): string
    {
        $text = '$' . number_format((float) self::round($value, 2), 2, '.', ',');

        return $withCode ? "{$text} {$currency}" : $text;
    }

    private static function norm(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }

        return (string) (is_numeric($value) ? $value : '0');
    }
}

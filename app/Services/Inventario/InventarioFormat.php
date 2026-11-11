<?php

namespace App\Services\Inventario;

use App\Support\Money;

/** Formato de cantidades y costos para las vistas del inventario. */
class InventarioFormat
{
    /** 8.0000 => "8", 2.5000 => "2.5", 1234.5 => "1,234.5". Con $signed agrega + a los positivos. */
    public static function qty(string|int|float|null $value, bool $signed = false): string
    {
        $n = (float) ($value ?? 0);
        $s = number_format($n, 4, '.', ',');
        $s = str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
        $s = $s === '' || $s === '-0' ? '0' : $s;

        return ($signed && $n > 0 ? '+' : '') . $s;
    }

    /** Costo unitario con 2 decimales (ej. $18.50); si tiene mas precision muestra hasta 4. */
    public static function cost(string|int|float|null $value): string
    {
        $n = (float) ($value ?? 0);
        $two = round($n, 2);

        return abs($n - $two) < 0.00005 ? Money::format((string) $n) : '$' . number_format($n, 4, '.', ',');
    }

    public static function money(string|int|float|null $value): string
    {
        return Money::format((string) ($value ?? 0));
    }
}

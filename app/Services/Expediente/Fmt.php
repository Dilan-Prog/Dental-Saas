<?php

namespace App\Services\Expediente;

use Illuminate\Support\Carbon;

/** Formatos de fecha/hora en español para las vistas del expediente. */
class Fmt
{
    public static function date(mixed $date, string $format = 'j M Y'): string
    {
        return $date ? self::tidy(Carbon::parse($date)->locale('es')->translatedFormat($format)) : '—';
    }

    public static function dateTime(mixed $date): string
    {
        return $date ? self::tidy(Carbon::parse($date)->locale('es')->translatedFormat('j M Y, H:i')) : '—';
    }

    /** "10 nov. 2026" -> "10 nov 2026" (sin punto en el mes abreviado, como en el diseño). */
    private static function tidy(string $text): string
    {
        return preg_replace('/(\p{L}{3,4})\./u', '$1', $text) ?? $text;
    }

    public static function ago(mixed $date): string
    {
        return $date ? Carbon::parse($date)->locale('es')->diffForHumans() : '—';
    }

    public static function doctor(?object $member): string
    {
        return $member?->user?->full_name ?? '—';
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    public static function money(string|float|int $amount): string
    {
        return '$'.number_format((float) $amount, 2);
    }
}

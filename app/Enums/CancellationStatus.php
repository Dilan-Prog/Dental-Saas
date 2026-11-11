<?php

namespace App\Enums;

enum CancellationStatus: string
{
    case Solicitada = 'solicitada';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case EnProceso = 'en_proceso';

    public function label(): string
    {
        return match ($this) {
            self::Solicitada => 'Solicitada',
            self::Aceptada => 'Aceptada',
            self::Rechazada => 'Rechazada',
            self::EnProceso => 'En proceso',
        };
    }

    /** @return array<string, string> valor => etiqueta en español */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum PlannedTreatmentStatus: string
{
    case Planeado = 'planeado';
    case EnProgreso = 'en_progreso';
    case Realizado = 'realizado';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Planeado => 'Planeado',
            self::EnProgreso => 'En progreso',
            self::Realizado => 'Realizado',
            self::Cancelado => 'Cancelado',
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

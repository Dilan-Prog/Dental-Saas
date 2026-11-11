<?php

namespace App\Enums;

enum PacRequestKind: string
{
    case Timbrado = 'timbrado';
    case Cancelacion = 'cancelacion';
    case ConsultaEstatus = 'consulta_estatus';

    public function label(): string
    {
        return match ($this) {
            self::Timbrado => 'Timbrado',
            self::Cancelacion => 'Cancelación',
            self::ConsultaEstatus => 'Consulta de estatus',
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

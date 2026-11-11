<?php

namespace App\Enums;

enum CollectionStatus: string
{
    case Pendiente = 'pendiente';
    case PagoParcial = 'pago_parcial';
    case Pagada = 'pagada';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::PagoParcial => 'Pago parcial',
            self::Pagada => 'Pagada',
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

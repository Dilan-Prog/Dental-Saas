<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Entregado = 'entregado';
    case Fallido = 'fallido';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Enviado => 'Enviado',
            self::Entregado => 'Entregado',
            self::Fallido => 'Fallido',
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

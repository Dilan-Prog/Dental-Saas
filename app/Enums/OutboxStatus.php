<?php

namespace App\Enums;

enum OutboxStatus: string
{
    case Pendiente = 'pendiente';
    case Procesando = 'procesando';
    case Enviado = 'enviado';
    case Fallido = 'fallido';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Procesando => 'Procesando',
            self::Enviado => 'Enviado',
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

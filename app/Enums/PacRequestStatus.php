<?php

namespace App\Enums;

enum PacRequestStatus: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Exitoso = 'exitoso';
    case Error = 'error';
    case Reintento = 'reintento';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Enviado => 'Enviado',
            self::Exitoso => 'Exitoso',
            self::Error => 'Error',
            self::Reintento => 'Reintento',
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

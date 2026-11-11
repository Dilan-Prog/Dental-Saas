<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Registrado = 'registrado';
    case Revertido = 'revertido';

    public function label(): string
    {
        return match ($this) {
            self::Registrado => 'Registrado',
            self::Revertido => 'Revertido',
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

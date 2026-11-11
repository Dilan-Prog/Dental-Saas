<?php

namespace App\Enums;

enum SigningStatus: string
{
    case Borrador = 'borrador';
    case Firmada = 'firmada';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Firmada => 'Firmada',
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

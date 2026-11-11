<?php

namespace App\Enums;

enum ToothCondition: string
{
    case Sano = 'sano';
    case Caries = 'caries';
    case Endodoncia = 'endodoncia';
    case Restauracion = 'restauracion';
    case Ausente = 'ausente';

    public function label(): string
    {
        return match ($this) {
            self::Sano => 'Sano',
            self::Caries => 'Caries',
            self::Endodoncia => 'Endodoncia',
            self::Restauracion => 'Restauración',
            self::Ausente => 'Ausente',
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

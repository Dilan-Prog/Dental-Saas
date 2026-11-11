<?php

namespace App\Enums;

enum CfdiPaymentMethod: string
{
    case Pue = 'PUE';
    case Ppd = 'PPD';

    public function label(): string
    {
        return match ($this) {
            self::Pue => 'Pago en una sola exhibición',
            self::Ppd => 'Pago en parcialidades o diferido',
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

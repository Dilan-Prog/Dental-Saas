<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Borrador = 'borrador';
    case Enviado = 'enviado';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviado => 'Enviado',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
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

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Borrador => [self::Enviado],
            self::Enviado => [self::Aprobado, self::Rechazado, self::Borrador],
            default => [],
        };
    }
}

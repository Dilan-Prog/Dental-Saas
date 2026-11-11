<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case InventarioInicial = 'inventario_inicial';
    case Entrada = 'entrada';
    case Salida = 'salida';
    case Ajuste = 'ajuste';
    case Reversion = 'reversion';

    public function label(): string
    {
        return match ($this) {
            self::InventarioInicial => 'Inventario inicial',
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
            self::Ajuste => 'Ajuste',
            self::Reversion => 'Reversión',
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

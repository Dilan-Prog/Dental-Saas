<?php

namespace App\Enums;

enum FiscalStatus: string
{
    case Borrador = 'borrador';
    case PendienteTimbrado = 'pendiente_timbrado';
    case Timbrada = 'timbrada';
    case CancelacionSolicitada = 'cancelacion_solicitada';
    case Cancelada = 'cancelada';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::PendienteTimbrado => 'Pendiente de timbrado',
            self::Timbrada => 'Timbrada',
            self::CancelacionSolicitada => 'Cancelación solicitada',
            self::Cancelada => 'Cancelada',
            self::Error => 'Error',
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

    /** Documento ya timbrado (no editable). */
    public function isStamped(): bool
    {
        return in_array($this, [self::Timbrada, self::CancelacionSolicitada, self::Cancelada], true);
    }
}

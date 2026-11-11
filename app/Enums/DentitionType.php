<?php

namespace App\Enums;

enum DentitionType: string
{
    case Permanente = 'permanente';
    case Temporal = 'temporal';

    public function label(): string
    {
        return match ($this) {
            self::Permanente => 'Permanente',
            self::Temporal => 'Temporal',
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

    /** Piezas FDI válidas para esta dentición. */
    public function validTeeth(): array
    {
        $ranges = $this === self::Permanente
            ? [[11, 18], [21, 28], [31, 38], [41, 48]]
            : [[51, 55], [61, 65], [71, 75], [81, 85]];

        $teeth = [];
        foreach ($ranges as [$from, $to]) {
            $teeth = array_merge($teeth, range($from, $to));
        }

        return $teeth;
    }

    public static function fromTooth(int $fdi): ?self
    {
        foreach (self::cases() as $case) {
            if (in_array($fdi, $case->validTeeth(), true)) {
                return $case;
            }
        }

        return null;
    }
}

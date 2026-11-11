<?php

namespace App\Enums;

enum NoteType: string
{
    case Simple = 'simple';
    case Soap = 'soap';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Nota simple',
            self::Soap => 'Nota SOAP',
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

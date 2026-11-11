<?php

namespace App\Enums;

enum ClinicalFileType: string
{
    case Radiografia = 'radiografia';
    case Consentimiento = 'consentimiento';
    case FotografiaIntraoral = 'fotografia_intraoral';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Radiografia => 'Radiografía',
            self::Consentimiento => 'Consentimiento',
            self::FotografiaIntraoral => 'Fotografía intraoral',
            self::Otro => 'Otro',
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

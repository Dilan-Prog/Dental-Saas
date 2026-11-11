<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pendiente = 'pendiente';
    case Confirmada = 'confirmada';
    case EnCurso = 'en_curso';
    case Completada = 'completada';
    case Cancelada = 'cancelada';
    case NoAsistio = 'no_asistio';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmada => 'Confirmada',
            self::EnCurso => 'En curso',
            self::Completada => 'Completada',
            self::Cancelada => 'Cancelada',
            self::NoAsistio => 'No asistió',
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

    /** Estados que ocupan agenda (los considera el trigger anti-empalme). */
    public static function blocking(): array
    {
        return [self::Pendiente, self::Confirmada, self::EnCurso];
    }

    /** @return string[] */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }

    /** Transiciones válidas desde este estado. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pendiente => [self::Confirmada, self::EnCurso, self::Cancelada, self::NoAsistio],
            self::Confirmada => [self::EnCurso, self::Cancelada, self::NoAsistio],
            self::EnCurso => [self::Completada, self::Cancelada],
            default => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Variante de color para insignias de la interfaz. */
    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Confirmada => 'info',
            self::EnCurso => 'primary',
            self::Completada => 'success',
            self::Cancelada => 'danger',
            self::NoAsistio => 'muted',
        };
    }
}

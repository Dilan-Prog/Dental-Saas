<?php

namespace App\Services\Expediente;

use Illuminate\Database\QueryException;

/**
 * Traduce errores de MySQL (triggers con SIGNAL, CHECK, FK, únicos) a mensajes claros.
 */
class DbErrors
{
    public static function message(QueryException $e, string $fallback = 'No se pudo guardar la información.'): string
    {
        $info = $e->errorInfo ?? [];
        $sqlState = $info[0] ?? null;
        $code = $info[1] ?? null;
        $driverMessage = (string) ($info[2] ?? '');

        // SIGNAL SQLSTATE '45000' de nuestros triggers: el texto ya está en español.
        if ($sqlState === '45000' && $driverMessage !== '') {
            return $driverMessage;
        }

        return match ((int) $code) {
            3819 => 'Algún dato no cumple una regla de integridad de la base de datos.',
            1062 => 'Ya existe un registro con esos datos.',
            1452 => 'Uno de los registros relacionados no existe o pertenece a otra clínica.',
            1451 => 'No se puede eliminar: existe información relacionada.',
            default => $fallback,
        };
    }
}

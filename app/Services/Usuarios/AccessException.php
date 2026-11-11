<?php

namespace App\Services\Usuarios;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Regla de negocio incumplida en Usuarios/Roles. El mensaje es apto para
 * mostrarse tal cual al usuario (en español).
 */
class AccessException extends RuntimeException
{
    /**
     * Convierte el error de un trigger/CHECK de MySQL (SQLSTATE 45000 / 3819)
     * en un AccessException legible. Si no es uno de esos errores, devuelve null.
     */
    public static function fromQuery(QueryException $e): ?self
    {
        $info = $e->errorInfo ?? [];
        $driverCode = (int) ($info[1] ?? 0);
        $text = (string) ($info[2] ?? '');

        if ($driverCode === 1644 && $text !== '') {
            // Los triggers escriben "nombre_trigger: mensaje".
            $text = preg_replace('/^[a-z0-9_]+:\s*/i', '', $text);

            return new self(ucfirst($text));
        }

        if ($driverCode === 3819) {
            return new self('La operación incumple una regla de integridad de la base de datos.');
        }

        if ($driverCode === 1451 || $driverCode === 1452) {
            return new self('El registro está relacionado con otros datos y no se puede modificar de esa forma.');
        }

        return null;
    }
}

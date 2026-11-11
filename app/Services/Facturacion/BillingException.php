<?php

namespace App\Services\Facturacion;

use RuntimeException;

/**
 * Regla de negocio de facturacion/cobranza incumplida. Los mensajes estan en
 * espanol y se muestran tal cual al usuario.
 */
class BillingException extends RuntimeException
{
    /** @param array<int,string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param array<int,string> $errors */
    public static function withErrors(string $message, array $errors): self
    {
        return new self($message, $errors);
    }
}

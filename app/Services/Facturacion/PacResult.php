<?php

namespace App\Services\Facturacion;

/**
 * Resultado de una llamada al PAC.
 *
 * - $final = false  => la solicitud queda pendiente; la respuesta llegara despues
 *                      (callback / consulta de estatus / comando facturacion:pac-respuesta).
 * - $final = true   => $success indica exito o error definitivo y $data trae la
 *                      respuesta tal cual se la entrega a PacResponseHandler.
 */
final class PacResult
{
    /**
     * @param  array<string,mixed>  $data  uuid_fiscal, fiscal_folio, stamped_at, xml_storage_key, pdf_storage_key,
     *                                     cancellation_status ('aceptada'|'rechazada'|'en_proceso')
     */
    public function __construct(
        public readonly bool $final,
        public readonly bool $success,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {
    }

    public static function pending(?string $message = null): self
    {
        return new self(false, true, 'PENDIENTE', $message);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(true, false, $code, $message);
    }

    public static function ok(array $data = [], ?string $code = '200', ?string $message = null): self
    {
        return new self(true, true, $code, $message, $data);
    }
}

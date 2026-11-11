<?php

namespace App\Services\Facturacion;

use App\Models\Invoice;
use App\Models\InvoiceCancellation;
use App\Models\PacRequest;

/**
 * PUNTO DE INTEGRACION con el PAC (proveedor autorizado de certificacion).
 *
 * El timbrado y la cancelacion reales los hace el PAC. Una implementacion real
 * debe enviar el CFDI y devolver un PacResult. Cuando la respuesta sea
 * definitiva (inmediata o por callback) hay que pasarla a
 * PacResponseHandler::apply(), que es el UNICO lugar que escribe uuid_fiscal,
 * stamped_at, xml/pdf y cierra el estado fiscal de la factura.
 *
 * Seleccion de implementacion: PacManager::client() (config services.pac.driver).
 */
interface PacClient
{
    /** Envia la factura a timbrar. */
    public function stamp(Invoice $invoice, PacRequest $request): PacResult;

    /** Solicita la cancelacion de una factura ya timbrada. */
    public function cancel(Invoice $invoice, InvoiceCancellation $cancellation, PacRequest $request): PacResult;
}

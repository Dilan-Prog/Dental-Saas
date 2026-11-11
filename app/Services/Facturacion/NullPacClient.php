<?php

namespace App\Services\Facturacion;

use App\Models\Invoice;
use App\Models\InvoiceCancellation;
use App\Models\PacRequest;

/**
 * Cliente PAC por defecto: NO timbra ni inventa folios fiscales.
 *
 * Solo deja la solicitud registrada (PacRequest pendiente) y la factura en
 * "pendiente de timbrado". El UUID fiscal, el sello y los archivos solo se
 * escriben cuando una respuesta REAL del PAC llega a PacResponseHandler.
 */
class NullPacClient implements PacClient
{
    public function stamp(Invoice $invoice, PacRequest $request): PacResult
    {
        return PacResult::pending('PAC no configurado: la solicitud de timbrado quedo en cola.');
    }

    public function cancel(Invoice $invoice, InvoiceCancellation $cancellation, PacRequest $request): PacResult
    {
        return PacResult::pending('PAC no configurado: la solicitud de cancelacion quedo en cola.');
    }
}

<?php

namespace App\Services\Facturacion;

use App\Enums\CancellationStatus;
use App\Enums\FiscalStatus;
use App\Enums\PacRequestKind;
use App\Enums\PacRequestStatus;
use App\Models\Invoice;
use App\Models\InvoiceCancellation;
use App\Models\PacRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UNICO punto que aplica respuestas del PAC (timbrado / cancelacion).
 *
 * Es el integrador quien lo invoca: desde un cliente PAC con respuesta
 * inmediata (PacResult final), desde un callback/webhook protegido o desde el
 * comando `php artisan facturacion:pac-respuesta`. Aqui, y solo aqui, se
 * escriben uuid_fiscal, stamped_at, fiscal_folio, xml/pdf y el cierre del
 * estado fiscal. Es idempotente: una solicitud ya exitosa no se reaplica.
 *
 * Claves de $response: success (bool), code, message,
 *   timbrado:    uuid_fiscal (obligatorio si success), fiscal_folio, stamped_at, xml_storage_key, pdf_storage_key
 *   cancelacion: cancellation_status ('aceptada'|'rechazada'|'en_proceso')
 */
class PacResponseHandler
{
    /** @param array<string,mixed> $response */
    public function apply(PacRequest $request, array $response): void
    {
        DB::transaction(function () use ($request, $response) {
            $request = PacRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->status === PacRequestStatus::Exitoso) {
                return; // idempotente
            }

            $invoice = Invoice::lockForUpdate()->findOrFail($request->invoice_id);
            $success = (bool) ($response['success'] ?? false);

            $request->response_code = $response['code'] ?? $request->response_code;
            $request->response_message = $response['message'] ?? $request->response_message;
            $request->responded_at = now();

            if ($request->kind === PacRequestKind::Timbrado) {
                $this->applyStamp($request, $invoice, $success, $response);
            } elseif ($request->kind === PacRequestKind::Cancelacion) {
                $this->applyCancellation($request, $invoice, $success, $response);
            } else {
                $request->status = $success ? PacRequestStatus::Exitoso : PacRequestStatus::Error;
                $request->save();
            }
        });
    }

    /** @param array<string,mixed> $r */
    protected function applyStamp(PacRequest $request, Invoice $invoice, bool $success, array $r): void
    {
        if (! $success) {
            $request->status = PacRequestStatus::Error;
            $request->error_detail = (string) ($r['message'] ?? 'El PAC rechazo el timbrado.');
            $request->save();

            if ($invoice->fiscal_status === FiscalStatus::PendienteTimbrado) {
                $invoice->fiscal_status = FiscalStatus::Error;
                $invoice->save();
            }

            return;
        }

        $uuid = strtolower(trim((string) ($r['uuid_fiscal'] ?? '')));
        if (! Str::isUuid($uuid)) {
            throw new BillingException('La respuesta del PAC no trae un UUID fiscal valido.');
        }
        $duplicated = Invoice::where('uuid_fiscal', $uuid)->where('id', '!=', $invoice->id)->exists();
        if ($duplicated) {
            throw new BillingException('El UUID fiscal ya esta asignado a otra factura.');
        }
        if (! in_array($invoice->fiscal_status, [FiscalStatus::PendienteTimbrado, FiscalStatus::Error], true)) {
            throw new BillingException('La factura no esta pendiente de timbrado.');
        }

        $folio = $r['fiscal_folio'] ?? null;
        if ($folio === null) {
            $q = Invoice::query();
            $invoice->series === null ? $q->whereNull('series') : $q->where('series', $invoice->series);
            $folio = (int) $q->max('fiscal_folio') + 1;
        }

        $invoice->fill([
            'uuid_fiscal' => $uuid,
            'fiscal_folio' => (int) $folio,
            'stamped_at' => $r['stamped_at'] ?? now(),
            'xml_storage_key' => $r['xml_storage_key'] ?? $invoice->xml_storage_key,
            'pdf_storage_key' => $r['pdf_storage_key'] ?? $invoice->pdf_storage_key,
            'fiscal_status' => FiscalStatus::Timbrada,
        ])->save();

        $request->status = PacRequestStatus::Exitoso;
        $request->error_detail = null;
        $request->save();
    }

    /** @param array<string,mixed> $r */
    protected function applyCancellation(PacRequest $request, Invoice $invoice, bool $success, array $r): void
    {
        $cancellation = InvoiceCancellation::where('pac_request_id', $request->id)->first();
        $status = (string) ($r['cancellation_status'] ?? ($success ? 'aceptada' : 'rechazada'));

        if ($status === 'en_proceso') {
            $request->status = PacRequestStatus::Enviado;
            $request->save();
            $cancellation?->forceFill(['status' => CancellationStatus::EnProceso])->save();

            return;
        }

        $accepted = $status === 'aceptada';
        $cancellation?->forceFill([
            'status' => $accepted ? CancellationStatus::Aceptada : CancellationStatus::Rechazada,
            'response_at' => now(),
            'response_detail' => $r['message'] ?? null,
        ])->save();

        $invoice->fiscal_status = $accepted ? FiscalStatus::Cancelada : FiscalStatus::Timbrada;
        $invoice->save();

        $request->status = PacRequestStatus::Exitoso;
        $request->save();
    }
}

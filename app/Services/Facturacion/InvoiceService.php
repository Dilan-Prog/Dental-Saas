<?php

namespace App\Services\Facturacion;

use App\Enums\CancellationStatus;
use App\Enums\CfdiType;
use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Enums\PacRequestKind;
use App\Enums\PacRequestStatus;
use App\Enums\QuoteStatus;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\InvoiceRelation;
use App\Models\Organization;
use App\Models\PacRequest;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Models\Quote;
use App\Models\TaxProfile;
use App\Models\User;
use App\Rules\Rfc;
use App\Support\Folio;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Altas, ediciones, emision (solicitud de timbrado) y cancelacion de facturas.
 * El timbrado real lo hace el PAC (ver PacClient / PacResponseHandler).
 */
class InvoiceService
{
    // ------------------------------------------------------------------ snapshots

    /** Datos fiscales del emisor (organizacion + configuracion fiscal). @return array<string,mixed> */
    public function issuerSnapshot(): array
    {
        $org = Organization::current();
        $s = $org?->fiscalSettings;

        return [
            'commercial_name' => $org?->commercial_name,
            'legal_name' => $org?->legal_name,
            'rfc' => $org?->rfc ? strtoupper($org->rfc) : null,
            'tax_regime_code' => $s?->tax_regime_code,
            'fiscal_postal_code' => $s?->fiscal_postal_code,
            'address_line' => $org?->address_line,
        ];
    }

    /** Datos fiscales del receptor. @return array<string,mixed> */
    public function receiverSnapshot(Patient $patient, string $mode = 'patient', ?string $profileId = null): array
    {
        if ($mode === 'publico_general') {
            $pg = config('sat.cp_publico_general');

            return [
                'mode' => 'publico_general',
                'patient_id' => $patient->id,
                'fiscal_profile_id' => null,
                'rfc' => $pg['rfc'],
                'legal_name' => $pg['nombre'],
                'tax_regime_code' => $pg['regimen'],
                'fiscal_postal_code' => $this->issuerSnapshot()['fiscal_postal_code'],
            ];
        }

        $profile = $profileId
            ? PatientFiscalProfile::where('patient_id', $patient->id)->where('id', $profileId)->first()
            : null;
        $profile ??= $patient->fiscalProfiles()->orderByDesc('is_default')->first();

        return [
            'mode' => 'patient',
            'patient_id' => $patient->id,
            'fiscal_profile_id' => $profile?->id,
            'rfc' => $profile ? strtoupper($profile->rfc) : null,
            'legal_name' => $profile?->legal_name,
            'tax_regime_code' => $profile?->tax_regime_code,
            'fiscal_postal_code' => $profile?->fiscal_postal_code,
        ];
    }

    // ------------------------------------------------------------------ lineas

    /**
     * Normaliza las partidas capturadas (resuelve perfiles de impuesto).
     *
     * @param  array<int, array<string,mixed>>  $items
     * @return array<int, array<string,mixed>>
     */
    public function normalizeLines(array $items): array
    {
        $profiles = TaxProfile::query()->get()->keyBy('id');
        $lines = [];

        foreach (array_values($items) as $item) {
            $objeto = (string) ($item['tax_object_code'] ?? '02');
            $profile = ! empty($item['tax_profile_id']) ? $profiles->get($item['tax_profile_id']) : null;

            $lines[] = [
                'description' => trim((string) $item['description']),
                'sat_product_key' => trim((string) $item['sat_product_key']),
                'sat_unit_key' => strtoupper(trim((string) ($item['sat_unit_key'] ?? 'E48'))),
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_pct' => $item['discount_pct'] ?? 0,
                'tax_object_code' => $objeto,
                'treatment_id' => $item['treatment_id'] ?? null,
                'quote_item_id' => $item['quote_item_id'] ?? null,
                'tax' => ($profile && in_array($objeto, ['02', '03'], true))
                    ? ['tax_code' => $profile->tax_code, 'rate' => (string) $profile->rate, 'is_exempt' => (bool) $profile->is_exempt]
                    : null,
            ];
        }

        return $lines;
    }

    /** Partidas precargadas desde un presupuesto. @return array<int, array<string,mixed>> */
    public function linesFromQuote(Quote $quote): array
    {
        $quote->loadMissing('items.treatment');
        $profiles = TaxProfile::query()->active()->get();
        $default = config('sat.claves_producto_sugeridas');
        $defaultKey = (string) array_key_first($default);

        return $quote->items->map(function ($qi) use ($profiles, $defaultKey) {
            $rate = (string) $qi->tax_rate_snapshot;
            $exempt = (bool) $qi->tax_is_exempt;
            $profile = $profiles->first(fn ($p) => (bool) $p->is_exempt === $exempt
                && ($exempt || Money::cmp((string) $p->rate, $rate, 6) === 0));

            return [
                'description' => $qi->description_snapshot,
                'sat_product_key' => $qi->sat_product_key_snapshot ?: ($qi->treatment?->sat_product_key ?: $defaultKey),
                'sat_unit_key' => $qi->treatment?->sat_unit_key ?: 'E48',
                'quantity' => (string) $qi->quantity,
                'unit_price' => (string) $qi->unit_price,
                'discount_pct' => (string) $qi->discount_pct,
                'tax_object_code' => ($exempt || Money::cmp($rate, '0', 6) > 0) ? '02' : '01',
                'tax_profile_id' => $profile?->id,
                'treatment_id' => $qi->treatment_id,
                'quote_item_id' => $qi->id,
            ];
        })->all();
    }

    // ------------------------------------------------------------------ alta / edicion

    /** @param array<string,mixed> $data */
    public function create(array $data, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($data) {
            $patient = Patient::findOrFail($data['patient_id']);
            $quote = ! empty($data['quote_id']) ? Quote::findOrFail($data['quote_id']) : null;
            if ($quote) {
                $this->assertQuoteInvoiceable($quote, $patient);
            }

            $lines = $this->normalizeLines($data['items']);
            $calc = InvoiceCalculator::calculate($lines);
            $settings = Organization::current()?->fiscalSettings;

            $invoice = new Invoice([
                'internal_folio' => Folio::next(Folio::INVOICE),
                'series' => $settings?->default_series ?: null,
                'cfdi_type' => CfdiType::Ingreso,
                'patient_id' => $patient->id,
                'doctor_member_id' => $data['doctor_member_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? Branch::main()->value('id'),
                'quote_id' => $quote?->id,
                'issuer_snapshot' => $this->issuerSnapshot(),
                'receiver_snapshot' => $this->receiverSnapshot($patient, $data['receiver_mode'] ?? 'patient', $data['fiscal_profile_id'] ?? null),
                'cfdi_use' => $data['cfdi_use'],
                'cfdi_payment_method' => $data['cfdi_payment_method'],
                'cfdi_payment_form' => $data['cfdi_payment_form'],
                'currency' => 'MXN',
                'exchange_rate' => 1,
                'subtotal' => $calc['subtotal'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'total' => $calc['total'],
                'fiscal_status' => FiscalStatus::Borrador,
                'collection_status' => CollectionStatus::Pendiente,
            ]);
            $invoice->save();

            $this->syncItems($invoice, $lines, $calc);

            return $invoice->load('items.taxes');
        });
    }

    /** @param array<string,mixed> $data */
    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isEditable()) {
                throw new BillingException('Solo se pueden editar facturas en borrador.');
            }

            $patient = Patient::findOrFail($data['patient_id']);
            $lines = $this->normalizeLines($data['items']);
            $calc = InvoiceCalculator::calculate($lines);

            $invoice->fill([
                'patient_id' => $patient->id,
                'doctor_member_id' => $data['doctor_member_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'issuer_snapshot' => $this->issuerSnapshot(),
                'receiver_snapshot' => $this->receiverSnapshot($patient, $data['receiver_mode'] ?? 'patient', $data['fiscal_profile_id'] ?? null),
                'cfdi_use' => $data['cfdi_use'],
                'cfdi_payment_method' => $data['cfdi_payment_method'],
                'cfdi_payment_form' => $data['cfdi_payment_form'],
                'subtotal' => $calc['subtotal'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'total' => $calc['total'],
            ])->save();

            $this->syncItems($invoice, $lines, $calc);

            return $invoice->load('items.taxes');
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isEditable()) {
                throw new BillingException('Solo se pueden eliminar facturas en borrador.');
            }
            $this->deleteItems($invoice);
            InvoiceRelation::where('invoice_id', $invoice->id)->delete();
            PacRequest::where('invoice_id', $invoice->id)->delete();
            $invoice->delete();
        });
    }

    // ------------------------------------------------------------------ emision

    /**
     * Valida y solicita el timbrado al PAC. La factura pasa a "pendiente de
     * timbrado"; el UUID fiscal solo lo escribe la respuesta real del PAC.
     */
    public function issue(Invoice $invoice, ?User $actor = null): Invoice
    {
        $invoice = Invoice::findOrFail($invoice->id);

        if (! in_array($invoice->fiscal_status, [FiscalStatus::Borrador, FiscalStatus::Error], true)) {
            throw new BillingException('La factura ya fue emitida.');
        }

        // Se refrescan snapshots con los datos fiscales vigentes antes de validar.
        $patient = Patient::findOrFail($invoice->patient_id);
        $receiver = (array) $invoice->receiver_snapshot;
        if ($invoice->cfdi_type === CfdiType::Ingreso) {
            $receiver = $this->receiverSnapshot($patient, $receiver['mode'] ?? 'patient', $receiver['fiscal_profile_id'] ?? null);
            $invoice->receiver_snapshot = $receiver;
        }
        $invoice->issuer_snapshot = $this->issuerSnapshot();

        $errors = FiscalValidator::issuanceErrors($invoice);
        if ($errors) {
            throw BillingException::withErrors('No se puede emitir la factura: revisa los datos fiscales.', $errors);
        }

        DB::transaction(function () use ($invoice, $receiver) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $settings = Organization::current()?->fiscalSettings;

            $attempt = (int) PacRequest::where('invoice_id', $invoice->id)->where('kind', PacRequestKind::Timbrado->value)->max('attempt_number') + 1;

            $invoice->fill([
                'issuer_snapshot' => $this->issuerSnapshot(),
                'receiver_snapshot' => $receiver,
                'issued_at' => $invoice->issued_at ?? now(),
                'fiscal_status' => FiscalStatus::PendienteTimbrado,
                'pac_provider' => $settings?->pac_provider,
            ])->save();

            $request = new PacRequest([
                'invoice_id' => $invoice->id,
                'kind' => PacRequestKind::Timbrado,
                'status' => PacRequestStatus::Pendiente,
                'idempotency_key' => 'stamp-' . $invoice->id . '-' . $attempt,
                'attempt_number' => $attempt,
                'requested_at' => now(),
            ]);
            $request->save();

            $result = PacManager::client()->stamp($invoice, $request);
            $this->applyClientResult($request, $result);
        });

        return Invoice::findOrFail($invoice->id);
    }

    // ------------------------------------------------------------------ cancelacion

    /**
     * Cancela una factura.
     *  - Borrador: se elimina (usar deleteDraft).
     *  - Pendiente de timbrado / con error (sin UUID): se cancela localmente.
     *  - Timbrada: solicita la cancelacion al PAC (motivo SAT 01-04).
     *
     * @param  array{reason_code:string, substitute_invoice_id?:?string, detail?:?string}  $data
     */
    public function cancel(Invoice $invoice, array $data, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->fiscal_status === FiscalStatus::Borrador) {
                throw new BillingException('Una factura en borrador se elimina, no se cancela.');
            }
            if (in_array($invoice->fiscal_status, [FiscalStatus::Cancelada, FiscalStatus::CancelacionSolicitada], true)) {
                throw new BillingException('La factura ya esta cancelada o en proceso de cancelacion.');
            }
            if (Money::cmp($invoice->appliedAmount(), '0') > 0) {
                throw new BillingException('La factura tiene pagos aplicados. Revierte los pagos antes de cancelarla.');
            }

            $reason = (string) $data['reason_code'];
            $substitute = null;
            if ($reason === '01') {
                $substitute = ! empty($data['substitute_invoice_id'])
                    ? Invoice::where('id', $data['substitute_invoice_id'])->where('id', '!=', $invoice->id)->first()
                    : null;
                if (! $substitute || $substitute->patient_id !== $invoice->patient_id
                    || in_array($substitute->fiscal_status, [FiscalStatus::Cancelada, FiscalStatus::Borrador], true)) {
                    throw new BillingException('El motivo 01 requiere una factura sustituta vigente del mismo paciente.');
                }
            }

            $cancellation = new InvoiceCancellation([
                'invoice_id' => $invoice->id,
                'reason_code' => $reason,
                'substitute_invoice_id' => $substitute?->id,
                'status' => CancellationStatus::Solicitada,
                'requested_by' => $actor?->id,
                'requested_at' => now(),
            ]);

            if ($substitute) {
                InvoiceRelation::firstOrCreate([
                    'invoice_id' => $substitute->id,
                    'related_invoice_id' => $invoice->id,
                    'relation_type_code' => '04',
                ]);
            }

            if (empty($invoice->uuid_fiscal)) {
                // Nunca se timbro: no hay nada que cancelar ante el SAT.
                $cancellation->status = CancellationStatus::Aceptada;
                $cancellation->response_at = now();
                $cancellation->response_detail = 'Cancelada antes de timbrar'
                    . (! empty($data['detail']) ? ': ' . $data['detail'] : '.');
                $cancellation->save();

                PacRequest::where('invoice_id', $invoice->id)
                    ->whereIn('status', [PacRequestStatus::Pendiente->value, PacRequestStatus::Reintento->value])
                    ->update([
                        'status' => PacRequestStatus::Error->value,
                        'error_detail' => 'Solicitud anulada: la factura se cancelo antes de timbrar.',
                        'responded_at' => now(),
                    ]);

                $invoice->fiscal_status = FiscalStatus::Cancelada;
                $invoice->save();
                $this->cancelUnstampedComplements($invoice);

                return $invoice;
            }

            $request = new PacRequest([
                'invoice_id' => $invoice->id,
                'kind' => PacRequestKind::Cancelacion,
                'status' => PacRequestStatus::Pendiente,
                'idempotency_key' => 'cancel-' . $invoice->id . '-' . Str::uuid(),
                'attempt_number' => 1,
                'requested_at' => now(),
            ]);
            $request->save();

            $cancellation->pac_request_id = $request->id;
            $cancellation->status = CancellationStatus::EnProceso;
            $cancellation->response_detail = ! empty($data['detail']) ? $data['detail'] : null;
            $cancellation->save();

            $invoice->fiscal_status = FiscalStatus::CancelacionSolicitada;
            $invoice->save();

            $result = PacManager::client()->cancel($invoice, $cancellation, $request);
            $this->applyClientResult($request, $result);

            return $invoice->refresh();
        });
    }

    // ------------------------------------------------------------------ helpers

    /** Aplica el resultado inmediato del cliente PAC (si es definitivo lo delega al handler). */
    protected function applyClientResult(PacRequest $request, PacResult $result): void
    {
        if (! $result->final) {
            $request->fill([
                'status' => PacRequestStatus::Pendiente,
                'response_code' => $result->code,
                'response_message' => $result->message,
            ])->save();

            return;
        }

        (new PacResponseHandler())->apply($request, array_merge($result->data, [
            'success' => $result->success,
            'code' => $result->code,
            'message' => $result->message,
        ]));
    }

    /** Un presupuesto solo se factura si esta aprobado, es del paciente y no tiene otra factura vigente. */
    protected function assertQuoteInvoiceable(Quote $quote, Patient $patient): void
    {
        if ($quote->patient_id !== $patient->id) {
            throw new BillingException('El presupuesto no pertenece al paciente seleccionado.');
        }
        if ($quote->status !== QuoteStatus::Aprobado) {
            throw new BillingException('Solo se pueden facturar presupuestos aprobados.');
        }
        $exists = Invoice::where('quote_id', $quote->id)
            ->where('fiscal_status', '!=', FiscalStatus::Cancelada->value)->exists();
        if ($exists) {
            throw new BillingException('El presupuesto ya tiene una factura vigente.');
        }
    }

    /** @param array<int, array<string,mixed>> $lines */
    protected function syncItems(Invoice $invoice, array $lines, array $calc): void
    {
        $this->deleteItems($invoice);

        foreach ($lines as $i => $line) {
            $c = $calc['items'][$i];
            $item = new InvoiceItem([
                'invoice_id' => $invoice->id,
                'line_number' => $i + 1,
                'treatment_id' => $line['treatment_id'] ?? null,
                'quote_item_id' => $line['quote_item_id'] ?? null,
                'description' => Str::limit($line['description'], 300, ''),
                'sat_product_key' => $line['sat_product_key'],
                'sat_unit_key' => $line['sat_unit_key'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount' => $c['discount'],
                'amount' => $c['amount'],
                'tax_object_code' => $line['tax_object_code'],
            ]);
            $item->save();

            foreach ($c['taxes'] as $tax) {
                (new InvoiceItemTax(array_merge($tax, ['invoice_item_id' => $item->id])))->save();
            }
        }
    }

    protected function deleteItems(Invoice $invoice): void
    {
        $ids = InvoiceItem::where('invoice_id', $invoice->id)->pluck('id');
        if ($ids->isNotEmpty()) {
            InvoiceItemTax::whereIn('invoice_item_id', $ids)->delete();
            InvoiceItem::whereIn('id', $ids)->delete();
        }
    }

    /** Complementos de pago sin timbrar de una factura que se cancela quedan cancelados. */
    protected function cancelUnstampedComplements(Invoice $invoice): void
    {
        $ids = DB::table('payment_complements')
            ->where('related_invoice_id', $invoice->id)->pluck('complement_invoice_id');
        if ($ids->isNotEmpty()) {
            Invoice::whereIn('id', $ids)->whereNull('uuid_fiscal')
                ->update(['fiscal_status' => FiscalStatus::Cancelada->value]);
        }
    }

    /** RFC valido (persona fisica, moral o generico) para la captura de formularios. */
    public static function isValidRfc(?string $rfc): bool
    {
        return $rfc !== null && Rfc::isValid($rfc);
    }
}

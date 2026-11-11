<?php

namespace App\Services\Facturacion;

use App\Enums\CfdiType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Rules\Rfc;
use App\Support\Money;

/**
 * Validaciones fiscales previas a emitir (timbrar) una factura CFDI 4.0.
 * Devuelven listas de mensajes en espanol; vacio = todo en orden.
 */
class FiscalValidator
{
    /** Regimenes que aplican a personas fisicas. */
    public const REGIMENES_FISICA = ['605', '606', '607', '608', '610', '611', '612', '614', '615', '616', '621', '622', '625', '626'];

    /** Regimenes que aplican a personas morales. */
    public const REGIMENES_MORAL = ['601', '603', '610', '620', '622', '623', '624', '626'];

    /** @return array<int,string> */
    public static function issuerErrors(?array $issuer = null): array
    {
        $issuer ??= (new InvoiceService())->issuerSnapshot();
        $e = [];

        $rfc = strtoupper((string) ($issuer['rfc'] ?? ''));
        if ($rfc === '' || ! Rfc::isValid($rfc, null, false)) {
            $e[] = 'Configura un RFC valido del consultorio (Configuracion > Facturacion).';
        }
        if (trim((string) ($issuer['legal_name'] ?? '')) === '') {
            $e[] = 'Configura la razon social del consultorio.';
        }
        if (! array_key_exists((string) ($issuer['tax_regime_code'] ?? ''), config('sat.regimenes', []))) {
            $e[] = 'Configura el regimen fiscal del emisor (catalogo SAT).';
        } elseif ($rfc !== '' && ! self::regimeMatchesRfc((string) $issuer['tax_regime_code'], $rfc)) {
            $e[] = 'El regimen fiscal del emisor no corresponde al tipo de RFC (persona fisica/moral).';
        }
        if (! preg_match('/^\d{5}$/', (string) ($issuer['fiscal_postal_code'] ?? ''))) {
            $e[] = 'Configura el codigo postal fiscal del emisor (5 digitos).';
        }

        $s = Organization::current()?->fiscalSettings;
        if (! $s || trim((string) $s->pac_provider) === '') {
            $e[] = 'Configura el proveedor PAC.';
        }
        if (! $s || trim((string) $s->csd_certificate_secret_ref) === '' || trim((string) $s->csd_key_secret_ref) === '') {
            $e[] = 'Configura las referencias del certificado de sello digital (CSD).';
        }
        if (! $s || trim((string) $s->pac_credentials_secret_ref) === '') {
            $e[] = 'Configura la referencia de las credenciales del PAC.';
        }

        return $e;
    }

    /** @return array<int,string> */
    public static function receiverErrors(array $receiver, ?string $cfdiUse): array
    {
        $e = [];
        $rfc = strtoupper((string) ($receiver['rfc'] ?? ''));

        if ($rfc === '' || ! Rfc::isValid($rfc)) {
            $e[] = 'El receptor no tiene un RFC valido. Captura sus datos fiscales en el expediente del paciente.';
        }
        if (trim((string) ($receiver['legal_name'] ?? '')) === '') {
            $e[] = 'El receptor no tiene nombre o razon social fiscal.';
        }
        $regime = (string) ($receiver['tax_regime_code'] ?? '');
        if (! array_key_exists($regime, config('sat.regimenes', []))) {
            $e[] = 'El receptor no tiene un regimen fiscal valido (catalogo SAT).';
        } elseif ($rfc !== '' && ! self::regimeMatchesRfc($regime, $rfc)) {
            $e[] = 'El regimen fiscal del receptor no corresponde al tipo de RFC (persona fisica/moral).';
        }
        if (! preg_match('/^\d{5}$/', (string) ($receiver['fiscal_postal_code'] ?? ''))) {
            $e[] = 'El receptor no tiene un codigo postal fiscal valido (5 digitos).';
        }

        $cfdiUse = (string) $cfdiUse;
        if (! array_key_exists($cfdiUse, config('sat.usos_cfdi', []))) {
            $e[] = 'El uso de CFDI no es valido (catalogo SAT).';
        } elseif ($rfc !== '' && ! self::useMatchesRfc($cfdiUse, $rfc)) {
            $e[] = 'El uso de CFDI "' . $cfdiUse . '" no es compatible con el RFC del receptor.';
        }

        if (in_array($rfc, Rfc::GENERICOS, true)) {
            $pg = config('sat.cp_publico_general');
            if ($rfc === ($pg['rfc'] ?? 'XAXX010101000') && ($regime !== ($pg['regimen'] ?? '616') || $cfdiUse !== ($pg['uso_cfdi'] ?? 'S01'))) {
                $e[] = 'Para publico en general usa regimen 616 y uso de CFDI S01.';
            }
        }

        return $e;
    }

    /** Errores que impiden emitir la factura. @return array<int,string> */
    public static function issuanceErrors(Invoice $invoice): array
    {
        $invoice->loadMissing('items.taxes');
        $e = [];

        if ($invoice->items->isEmpty()) {
            $e[] = 'La factura no tiene conceptos.';
        }
        if ($invoice->cfdi_type === CfdiType::Ingreso && Money::cmp($invoice->total, '0') <= 0) {
            $e[] = 'El total de la factura debe ser mayor a cero.';
        }

        foreach ($invoice->items as $item) {
            if (! preg_match('/^\d{8}$/', (string) $item->sat_product_key)) {
                $e[] = "Concepto {$item->line_number}: la clave de producto/servicio del SAT debe tener 8 digitos.";
            }
            if (! array_key_exists((string) $item->sat_unit_key, config('sat.unidades', []))) {
                $e[] = "Concepto {$item->line_number}: la clave de unidad no es valida.";
            }
            if (! array_key_exists((string) $item->tax_object_code, config('sat.objeto_impuesto', []))) {
                $e[] = "Concepto {$item->line_number}: el objeto de impuesto no es valido.";
            }
        }

        $method = $invoice->cfdi_payment_method?->value;
        $form = (string) $invoice->cfdi_payment_form;
        if (! array_key_exists($form, config('sat.formas_pago', []))) {
            $e[] = 'La forma de pago no es valida (catalogo SAT).';
        } elseif ($invoice->cfdi_type === CfdiType::Ingreso) {
            if ($method === 'PPD' && $form !== '99') {
                $e[] = 'Con metodo de pago PPD la forma de pago debe ser "99 - Por definir".';
            }
            if ($method === 'PUE' && $form === '99') {
                $e[] = 'Con metodo de pago PUE debes indicar la forma de pago real (no "99").';
            }
        }

        $e = array_merge($e, self::issuerErrors());
        $e = array_merge($e, self::receiverErrors((array) $invoice->receiver_snapshot, $invoice->cfdi_use));

        if ($invoice->cfdi_type === CfdiType::Pago) {
            $related = $invoice->complementRows()->with('relatedInvoice')->get()->pluck('relatedInvoice')->filter();
            if ($related->isEmpty() || $related->contains(fn ($r) => empty($r->uuid_fiscal))) {
                $e[] = 'El complemento de pago requiere que la factura relacionada ya este timbrada (UUID fiscal).';
            }
        }

        return array_values(array_unique($e));
    }

    public static function regimeMatchesRfc(string $regime, string $rfc): bool
    {
        $rfc = strtoupper($rfc);
        if (in_array($rfc, Rfc::GENERICOS, true)) {
            return true;
        }

        return strlen($rfc) === 13
            ? in_array($regime, self::REGIMENES_FISICA, true)
            : in_array($regime, self::REGIMENES_MORAL, true);
    }

    public static function useMatchesRfc(string $use, string $rfc): bool
    {
        $rfc = strtoupper($rfc);
        // Usos D01-D10 (deducciones personales) solo para personas fisicas con RFC propio.
        if (str_starts_with($use, 'D')) {
            return strlen($rfc) === 13 && ! in_array($rfc, Rfc::GENERICOS, true);
        }

        return true;
    }
}

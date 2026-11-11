<?php

namespace Database\Seeders;

use App\Enums\QuoteStatus;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\OrganizationFiscalSetting;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Models\Quote;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Facturacion\InvoiceService;
use App\Services\Facturacion\PaymentService;
use App\Support\Folio;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facturas demo coherentes con el prototipo (Facturacion): una desde un
 * presupuesto aprobado con pago parcial, una pagada por transferencia, una
 * pendiente, una cancelada antes de timbrar y un borrador.
 *
 * NO inventa UUID fiscales: las facturas emitidas quedan "pendiente de
 * timbrado" (el UUID solo lo asigna una respuesta real del PAC). Los pagos son
 * con tarjeta/transferencia (el efectivo exige una caja abierta). Idempotente:
 * si ya existen facturas no hace nada.
 */
class DemoFacturacionSeeder extends Seeder
{
    public function run(): void
    {
        if (Invoice::query()->exists()) {
            return;
        }

        $svc = app(InvoiceService::class);
        $payments = app(PaymentService::class);
        $admin = User::where('email', 'admin@dental.test')->first();
        $branchId = (string) Branch::main()->value('id');
        $doctorId = OrganizationMember::active()->whereHas('role', fn ($q) => $q->where('code', 'doctor'))->value('id');
        $iva = TaxProfile::query()->where('is_exempt', false)->orderByDesc('rate')->first();

        $this->ensureIssuerSettings();

        // Folios consecutivos tipo F-0225... si todavia no se ha facturado nada.
        DB::table('folio_sequences')
            ->where('organization_id', Organization::currentId())
            ->where('document_type', Folio::INVOICE)->where('series', '')
            ->where('last_value', '<', 224)->update(['last_value' => 224]);

        // ---- F-0225 · Fernanda Lozano Cruz · cancelada antes de timbrar ----
        $fernanda = $this->patient('Fernanda Lozano Cruz', ['LOCF900418M62', 'Fernanda Lozano Cruz', '612', '03100', 'D01']);
        $inv = $this->manual($svc, $fernanda, $branchId, $doctorId, $iva, 'Extracción simple y ajuste de ortodoncia', '6500', 'PUE', '03');
        $this->date($inv, 22);
        $svc->issue($inv, $admin);
        $svc->cancel($inv, ['reason_code' => '02', 'detail' => 'Capturada con importe incorrecto'], $admin);

        // ---- F-0226 · Jorge Salinas Cano · pendiente ----
        $jorge = $this->patient('Jorge Salinas Cano', ['SACJ790225QX9', 'Jorge Salinas Cano', '612', '04510', 'D01']);
        $inv = $this->manual($svc, $jorge, $branchId, $doctorId, $iva, 'Rehabilitación con coronas de zirconia', '21000', 'PUE', '03');
        $this->date($inv, 14);
        $svc->issue($inv, $admin);

        // ---- F-0227 · Roberto Mendoza Lira · pagada por transferencia ----
        $roberto = $this->patient('Roberto Mendoza Lira', ['MELR830712HD5', 'Roberto Mendoza Lira', '612', '11520', 'D01']);
        $inv = $this->manual($svc, $roberto, $branchId, $doctorId, $iva, 'Endodoncia molar inferior', '3200', 'PUE', '03');
        $this->date($inv, 9);
        $svc->issue($inv, $admin);
        $payments->register([
            'patient_id' => $roberto->id, 'branch_id' => $branchId, 'paid_at' => now()->subDays(9)->setTime(12, 40),
            'method' => 'transferencia', 'reference' => 'SPEI-30912', 'idempotency_key' => 'demo-pay-' . $inv->id,
            'applications' => [$inv->id => $inv->total],
        ], $admin);

        // ---- F-0228 · Valeria Domínguez Soto · desde presupuesto aprobado, pago parcial ----
        $valeria = $this->patient('Valeria Domínguez Soto', ['DOSV850312KT4', 'Valeria Domínguez Soto', '612', '06700', 'D01']);
        $quote = Quote::query()->where('patient_id', $valeria->id)->where('status', QuoteStatus::Aprobado->value)
            ->whereDoesntHave('invoices')->first();
        if ($quote) {
            $inv = $svc->create([
                'patient_id' => $valeria->id, 'quote_id' => $quote->id, 'branch_id' => $quote->branch_id,
                'doctor_member_id' => $quote->doctor_member_id, 'receiver_mode' => 'patient',
                'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04',
                'items' => $svc->linesFromQuote($quote),
            ], $admin);
        } else {
            $inv = $this->manual($svc, $valeria, $branchId, $doctorId, $iva, 'Tratamiento dental', '12000', 'PUE', '04');
        }
        $this->date($inv, 5);
        $svc->issue($inv, $admin);
        $payments->register([
            'patient_id' => $valeria->id, 'branch_id' => $inv->branch_id, 'paid_at' => now()->subDays(5)->setTime(17, 5),
            'method' => 'tarjeta', 'reference' => 'AUTH-88421', 'idempotency_key' => 'demo-pay-' . $inv->id,
            'applications' => [$inv->id => Money::percent($inv->total, 45)],
        ], $admin);

        // ---- Borrador (publico en general) ----
        $pg = Patient::query()->where('full_name', 'Patricio Guerra Méndez')->first();
        if ($pg) {
            $draft = $this->manual($svc, $pg, $branchId, $doctorId, $iva, 'Resina compuesta', '1800', 'PUE', '28', 'publico_general', 'S01');
            $this->date($draft, 1);
        }
    }

    /** Factura manual de un solo concepto con IVA 16%. */
    private function manual(InvoiceService $svc, Patient $patient, string $branchId, ?string $doctorId, ?TaxProfile $iva,
                            string $description, string $price, string $method, string $form,
                            string $mode = 'patient', string $use = 'D01'): Invoice
    {
        return $svc->create([
            'patient_id' => $patient->id, 'branch_id' => $branchId, 'doctor_member_id' => $doctorId,
            'receiver_mode' => $mode, 'cfdi_use' => $use, 'cfdi_payment_method' => $method, 'cfdi_payment_form' => $form,
            'items' => [[
                'description' => $description, 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
                'quantity' => '1', 'unit_price' => $price, 'discount_pct' => '0',
                'tax_object_code' => '02', 'tax_profile_id' => $iva?->id,
            ]],
        ]);
    }

    private function date(Invoice $invoice, int $daysAgo): void
    {
        $at = now()->subDays($daysAgo)->setTime(10, 0);
        $update = ['created_at' => $at];
        if ($invoice->fresh()->issued_at !== null) {
            $update['issued_at'] = $at;
        }
        Invoice::query()->whereKey($invoice->id)->toBase()->update($update);
    }

    /** Reutiliza al paciente (lo siembra Pacientes) o lo crea minimo, con datos fiscales validos. */
    private function patient(string $name, array $fiscal): Patient
    {
        $patient = Patient::query()->where('full_name', $name)->first();
        if (! $patient) {
            $patient = new Patient([
                'folio' => Folio::next(Folio::PATIENT),
                'full_name' => $name,
                'sex' => 'unspecified',
                'phone' => '55' . random_int(10000000, 99999999),
                'status' => 'active',
            ]);
            $patient->save();
            MedicalRecord::firstOrCreate(['patient_id' => $patient->id]);
        }

        if (! PatientFiscalProfile::where('patient_id', $patient->id)->exists()) {
            (new PatientFiscalProfile([
                'patient_id' => $patient->id, 'rfc' => $fiscal[0], 'legal_name' => $fiscal[1],
                'tax_regime_code' => $fiscal[2], 'fiscal_postal_code' => $fiscal[3],
                'default_cfdi_use' => $fiscal[4], 'is_default' => true,
            ]))->save();
        }

        return $patient;
    }

    /** Completa solo lo que falte de la configuracion fiscal del emisor (referencias ficticias de demo). */
    private function ensureIssuerSettings(): void
    {
        $org = Organization::current();
        if ($org && ! $org->rfc) {
            $org->forceFill(['rfc' => 'CUS200115AB3', 'legal_name' => $org->legal_name ?: 'Clínica Dental Pro S.A. de C.V.'])->save();
        }

        $s = OrganizationFiscalSetting::query()->first();
        if (! $s) {
            return;
        }
        $s->forceFill([
            'tax_regime_code' => $s->tax_regime_code ?: '601',
            'fiscal_postal_code' => $s->fiscal_postal_code ?: '06000',
            'pac_provider' => $s->pac_provider ?: 'Finkok',
            'csd_certificate_secret_ref' => $s->csd_certificate_secret_ref ?: 'secret://demo/csd-certificado',
            'csd_key_secret_ref' => $s->csd_key_secret_ref ?: 'secret://demo/csd-llave',
            'pac_credentials_secret_ref' => $s->pac_credentials_secret_ref ?: 'secret://demo/pac-credenciales',
        ])->save();
    }
}

<?php

namespace Tests\Feature\Facturacion\Concerns;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationFiscalSetting;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Facturacion\InvoiceService;
use App\Support\Folio;
use Illuminate\Support\Str;

/** Datos base para las pruebas de Facturacion (usa BaseSeeder del TestCase). */
trait BuildsBillingFixtures
{
    private int $fixtureSeq = 0;

    /** Deja el emisor listo para emitir (RFC, razon social, regimen, CP, CSD y PAC). */
    protected function configureIssuer(array $override = []): void
    {
        $org = Organization::current();
        $org->forceFill(['rfc' => 'CUS200115AB3', 'legal_name' => 'Clínica Dental Pro S.A. de C.V.'])->save();

        $s = OrganizationFiscalSetting::query()->first();
        $s->forceFill(array_merge([
            'tax_regime_code' => '601', 'fiscal_postal_code' => '06000', 'pac_provider' => 'Finkok',
            'default_series' => 'A',
            'csd_certificate_secret_ref' => 'secret://test/cert', 'csd_key_secret_ref' => 'secret://test/key',
            'pac_credentials_secret_ref' => 'secret://test/pac',
        ], $override))->save();
        Organization::flushCurrent();
    }

    protected function makePatient(bool $fiscal = true, string $name = 'Paciente Prueba', string $rfc = 'DOSV850312KT4'): Patient
    {
        $this->fixtureSeq++;
        $p = new Patient([
            'folio' => 'PAC-T' . str_pad((string) $this->fixtureSeq, 3, '0', STR_PAD_LEFT) . Str::random(3),
            'full_name' => $name . ' ' . $this->fixtureSeq,
            'sex' => 'unspecified',
            'status' => 'active',
        ]);
        $p->save();

        if ($fiscal) {
            (new PatientFiscalProfile([
                'patient_id' => $p->id, 'rfc' => $rfc, 'legal_name' => $p->full_name,
                'tax_regime_code' => '612', 'fiscal_postal_code' => '06700',
                'default_cfdi_use' => 'D01', 'is_default' => true,
            ]))->save();
        }

        return $p;
    }

    protected function iva(): TaxProfile
    {
        return TaxProfile::query()->where('is_exempt', false)->orderByDesc('rate')->firstOrFail();
    }

    /** Factura borrador de 1 concepto con IVA 16% (precio sin impuesto). */
    protected function makeDraft(Patient $patient, string $price = '1000', array $override = []): Invoice
    {
        return app(InvoiceService::class)->create(array_merge([
            'patient_id' => $patient->id,
            'branch_id' => (string) Branch::main()->value('id'),
            'receiver_mode' => 'patient',
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04',
            'items' => [[
                'description' => 'Limpieza dental', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
                'quantity' => '1', 'unit_price' => $price, 'discount_pct' => '0',
                'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
            ]],
        ], $override));
    }

    /** Factura emitida (pendiente de timbrado). */
    protected function makeIssued(Patient $patient, string $price = '1000', array $override = []): Invoice
    {
        $this->configureIssuer();

        return app(InvoiceService::class)->issue($this->makeDraft($patient, $price, $override));
    }

    protected function openCashSession(?User $user = null): CashSession
    {
        $branch = Branch::main()->firstOrFail();
        $register = CashRegister::firstOrCreate(['branch_id' => $branch->id, 'name' => 'Caja principal'], ['status' => 'active']);

        return CashSession::create([
            'cash_register_id' => $register->id,
            'opened_by' => ($user ?? User::where('email', 'admin@dental.test')->first())->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 500,
            'status' => 'abierta',
        ]);
    }

    protected function makeApprovedQuote(Patient $patient, string $status = 'aprobado'): Quote
    {
        $doctor = $this->memberId($this->userByEmail('doctor@dental.test'));
        $quote = new Quote([
            'folio' => 'PRE-T' . Str::random(5),
            'patient_id' => $patient->id, 'doctor_member_id' => $doctor,
            'branch_id' => (string) Branch::main()->value('id'),
            'quote_date' => now()->toDateString(), 'status' => $status, 'currency' => 'MXN',
            'subtotal' => '2000.00', 'discount_total' => '200.00', 'tax_total' => '288.00', 'total' => '2088.00',
        ]);
        $quote->save();

        (new QuoteItem([
            'quote_id' => $quote->id, 'line_number' => 1, 'description_snapshot' => 'Resina compuesta',
            'sat_product_key_snapshot' => '85121800', 'quantity' => '2', 'unit_price' => '1000',
            'discount_pct' => '10', 'tax_rate_snapshot' => '0.160000', 'tax_is_exempt' => false,
            'line_subtotal' => '2000.00', 'line_discount' => '200.00', 'line_tax' => '288.00', 'line_total' => '2088.00',
        ]))->save();

        return $quote;
    }

    protected function paymentPayload(Invoice $invoice, array $override = []): array
    {
        return array_merge([
            'amount' => $invoice->balance(),
            'method' => 'tarjeta',
            'reference' => 'AUTH-' . random_int(1000, 9999),
            'idempotency_key' => (string) Str::uuid(),
        ], $override);
    }
}

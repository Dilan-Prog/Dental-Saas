<?php

namespace Tests\Feature\QA\Billing;

use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Models\FinancialMovement;
use App\Models\Invoice;
use App\Models\PacRequest;
use App\Models\Payment;
use App\Models\PaymentApplication;
use Illuminate\Support\Str;
use Tests\Feature\Facturacion\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/** QA: validación de formularios, dominios SAT y coherencia de facturación/pagos. */
class BillingQaTest extends TestCase
{
    use BuildsBillingFixtures;

    private function invoicePayload(array $override = [], array $item = []): array
    {
        $patient = $this->makePatient();

        return array_replace_recursive([
            'patient_id' => $patient->id,
            'branch_id' => (string) \App\Models\Branch::main()->value('id'),
            'receiver_mode' => 'patient',
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04',
            'items' => [array_merge([
                'description' => 'Limpieza dental', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
                'quantity' => '1', 'unit_price' => '1000', 'discount_pct' => '0',
                'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
            ], $item)],
        ], $override);
    }

    // ------------------------------------------------------------ formulario de factura

    public function test_invoice_form_rejects_invalid_catalog_and_numeric_values(): void
    {
        $this->actingAsAdmin();
        $cases = [
            'cfdi_use' => [['cfdi_use' => 'ZZ9'], 'cfdi_use'],
            'cfdi_use_no_aplica' => [['cfdi_use' => 'CP01'], 'cfdi_use'],
            'metodo' => [['cfdi_payment_method' => 'XXX'], 'cfdi_payment_method'],
            'forma' => [['cfdi_payment_form' => '77'], 'cfdi_payment_form'],
            'ppd_con_forma_real' => [['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '03'], 'cfdi_payment_form'],
            'pue_con_99' => [['cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '99'], 'cfdi_payment_form'],
            'receptor' => [['receiver_mode' => 'otro'], 'receiver_mode'],
            'paciente' => [['patient_id' => (string) Str::uuid()], 'patient_id'],
            'sucursal' => [['branch_id' => 'xyz'], 'branch_id'],
        ];
        foreach ($cases as $name => [$override, $key]) {
            $this->post(route('facturacion.store'), $this->invoicePayload($override))
                ->assertSessionHasErrors($key, null, 'default');
        }

        $itemCases = [
            ['sat_product_key' => '1234567'], ['sat_product_key' => '123456789'], ['sat_unit_key' => 'ZZZ'],
            ['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.00001'],
            ['unit_price' => '-5'], ['unit_price' => '1.00001'],
            ['discount_pct' => '100.5'], ['discount_pct' => '-1'],
            ['description' => ''], ['description' => str_repeat('a', 301)],
            ['tax_object_code' => '09'], ['tax_profile_id' => ''],       // objeto 02 sin impuesto
            ['tax_object_code' => '01'],                                    // objeto 01 con impuesto
        ];
        foreach ($itemCases as $item) {
            $r = $this->post(route('facturacion.store'), $this->invoicePayload([], $item));
            $this->assertTrue(session()->has('errors'), 'Debio rechazar: '.json_encode($item));
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_invoice_extreme_amount_is_rejected_without_500(): void
    {
        $this->actingAsAdmin();
        $r = $this->post(route('facturacion.store'), $this->invoicePayload([], ['quantity' => '999999', 'unit_price' => '9999999999']));
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(0, Invoice::count());
    }

    // ------------------------------------------------------------ emision / PAC / cancelacion

    public function test_emit_requires_fiscal_data_and_never_invents_a_fiscal_uuid(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient(false); // sin perfil fiscal
        $draft = $this->makeDraft($patient, '1000', ['receiver_mode' => 'patient']);
        $this->configureIssuer();

        $this->post(route('facturacion.emit', $draft))->assertSessionHas('error');
        $this->assertSame(FiscalStatus::Borrador, $draft->fresh()->fiscal_status);

        $ok = $this->makeDraft($this->makePatient(), '1000');
        $this->post(route('facturacion.emit', $ok))->assertSessionHas('success');
        $ok->refresh();
        $this->assertSame(FiscalStatus::PendienteTimbrado, $ok->fiscal_status);
        $this->assertNull($ok->uuid_fiscal);
        $this->assertNull($ok->stamped_at);
        $this->assertNull($ok->fiscal_folio);
        $this->assertSame(1, PacRequest::where('invoice_id', $ok->id)->count());

        // Emitir dos veces no duplica la solicitud al PAC.
        $this->post(route('facturacion.emit', $ok));
        $this->assertSame(1, PacRequest::where('invoice_id', $ok->id)->where('kind', 'timbrado')->count());
    }

    public function test_issued_invoice_is_read_only(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $this->put(route('facturacion.update', $inv), $this->invoicePayload())->assertSessionHas('error');
        $this->delete(route('facturacion.destroy', $inv));
        $this->assertDatabaseHas('invoices', ['id' => $inv->id]);
    }

    public function test_cancellation_validation(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '05'])->assertSessionHasErrors('reason_code', null, 'cancelacion');
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '01'])->assertSessionHasErrors('substitute_invoice_id', null, 'cancelacion');
        // La sustituta no puede ser la misma factura.
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '01', 'substitute_invoice_id' => $inv->id]);
        $this->assertNotSame(FiscalStatus::Cancelada, $inv->fresh()->fiscal_status);
        $this->assertNotSame(FiscalStatus::CancelacionSolicitada, $inv->fresh()->fiscal_status);
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02', 'detail' => str_repeat('x', 501)])->assertSessionHasErrors('detail', null, 'cancelacion');
    }

    public function test_cancel_is_blocked_when_payments_are_applied(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->post(route('pagos.store', $inv), $this->paymentPayload($inv, ['amount' => '100.00']));

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02']);
        $this->assertNotSame(FiscalStatus::Cancelada, $inv->fresh()->fiscal_status);
    }

    // ------------------------------------------------------------ pagos

    private function pay(Invoice $invoice, array $override = [])
    {
        return $this->post(route('pagos.store', $invoice), $this->paymentPayload($invoice, $override));
    }

    public function test_payment_form_validation(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000'); // 1160.00
        $this->actingAsAdmin();

        foreach (['0', '-5', '0.001', 'abc', '', '1160.01', '99999999999999'] as $amount) {
            $this->pay($inv, ['amount' => $amount])->assertSessionHasErrors('amount', null, 'pago');
        }
        $this->pay($inv, ['method' => 'bitcoin'])->assertSessionHasErrors('method', null, 'pago');
        $this->pay($inv, ['method' => 'transferencia', 'reference' => ''])->assertSessionHasErrors('reference', null, 'pago');
        $this->pay($inv, ['reference' => str_repeat('r', 121)])->assertSessionHasErrors('reference', null, 'pago');
        $this->pay($inv, ['paid_at' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_at', null, 'pago');
        $this->pay($inv, ['idempotency_key' => ''])->assertSessionHasErrors('idempotency_key', null, 'pago');
        $this->assertSame(0, Payment::count());
    }

    public function test_double_submit_with_same_idempotency_key_registers_one_payment(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $key = (string) Str::uuid();

        $this->pay($inv, ['amount' => '300.00', 'idempotency_key' => $key]);
        $r = $this->pay($inv, ['amount' => '300.00', 'idempotency_key' => $key]);

        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, FinancialMovement::count());
        $this->assertSame('300.00', (string) PaymentApplication::sum('applied_amount'));
    }

    public function test_transfer_reference_must_be_unique(): void
    {
        $a = $this->makeIssued($this->makePatient(), '1000');
        $b = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($a, ['amount' => '100.00', 'method' => 'transferencia', 'reference' => 'SPEI-123'])->assertSessionHas('success');
        $this->pay($b, ['amount' => '100.00', 'method' => 'transferencia', 'reference' => 'SPEI-123'])
            ->assertSessionHasErrors('reference', null, 'pago');
        // Mismo texto con otra capitalización / espacios tampoco debe duplicar.
        $this->pay($b, ['amount' => '100.00', 'method' => 'transferencia', 'reference' => ' spei-123 ']);
        $this->assertSame(1, Payment::where('method', 'transferencia')->count());
    }

    public function test_cash_payment_requires_open_cash_session(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '100.00', 'method' => 'efectivo', 'reference' => ''])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());

        $session = $this->openCashSession();
        $this->pay($inv, ['amount' => '100.00', 'method' => 'efectivo', 'reference' => ''])->assertSessionHas('success');
        $this->assertSame($session->id, FinancialMovement::firstOrFail()->cash_session_id);
    }

    public function test_payments_on_draft_or_cancelled_invoices_are_rejected(): void
    {
        $draft = $this->makeDraft($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->pay($draft, ['amount' => '100.00']);
        $this->assertSame(0, Payment::count());

        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02']);
        $this->pay($inv->fresh(), ['amount' => '100.00']);
        $this->assertSame(0, Payment::count());
    }

    public function test_reversal_restores_balance_and_cannot_repeat(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->pay($inv, ['amount' => '1160.00']);
        $payment = Payment::firstOrFail();
        $this->assertSame(CollectionStatus::Pagada, $inv->fresh()->collection_status);

        $this->post(route('pagos.reverse', $payment), ['reason' => 'abc'])->assertSessionHasErrors('reason', null, 'reversion_'.$payment->id);
        $this->post(route('pagos.reverse', $payment), ['reason' => 'Cobro duplicado por error']);
        $this->assertSame(CollectionStatus::Pendiente, $inv->fresh()->collection_status);
        $this->assertSame('1160.00', $inv->fresh()->balance());

        $this->post(route('pagos.reverse', $payment), ['reason' => 'Intento repetido de reversa']);
        $this->assertSame(1, \App\Models\PaymentReversal::count());
        $this->assertSame(2, FinancialMovement::count()); // ingreso + su reversión, nada más
    }

    public function test_multi_payment_distributes_and_never_exceeds_balances(): void
    {
        $p = $this->makePatient();
        $a = $this->makeIssued($p, '1000');
        $b = $this->makeIssued($p, '500');
        $this->actingAsAdmin();

        $this->post(route('pagos.storeMulti'), [
            'patient_id' => $p->id, 'method' => 'tarjeta', 'reference' => 'T-1', 'idempotency_key' => (string) Str::uuid(),
            'applications' => [$a->id => '1160.00', $b->id => '580.01'],
        ])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());

        $this->post(route('pagos.storeMulti'), [
            'patient_id' => $p->id, 'method' => 'tarjeta', 'reference' => 'T-2', 'idempotency_key' => (string) Str::uuid(),
            'applications' => [$a->id => '1160.00', $b->id => '300.00'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(CollectionStatus::Pagada, $a->fresh()->collection_status);
        $this->assertSame(CollectionStatus::PagoParcial, $b->fresh()->collection_status);
        $this->assertSame('1460.00', (string) Payment::sum('amount'));
        $this->assertSame('1460.00', (string) PaymentApplication::sum('applied_amount'));
    }

    // ------------------------------------------------------------ coherencia

    public function test_invoice_numbers_are_consecutive_and_unique(): void
    {
        $this->configureIssuer();
        $p = $this->makePatient();
        $folios = [];
        for ($i = 0; $i < 4; $i++) {
            $folios[] = $this->makeDraft($p, '100')->internal_folio;
        }
        $nums = array_map(fn ($f) => (int) substr($f, 2), $folios);
        $this->assertSame(range($nums[0], $nums[0] + 3), $nums);
        $this->assertCount(4, array_unique($folios));
    }

    public function test_invoice_uses_the_sequence_of_its_series(): void
    {
        $this->configureIssuer(['default_series' => 'A']);
        \App\Models\FolioSequence::updateOrCreate(
            ['document_type' => 'invoice', 'series' => 'A'],
            ['prefix' => 'F-', 'last_value' => 500]
        );

        $inv = $this->makeDraft($this->makePatient(), '100');

        $this->assertSame('A', $inv->series);
        $this->assertSame('F-0501', $inv->internal_folio);
        $this->assertSame(501, (int) \App\Models\FolioSequence::where('document_type', 'invoice')->where('series', 'A')->value('last_value'));
    }

    // ------------------------------------------------------------ presupuesto -> factura

    public function test_invoice_from_quote_cannot_exceed_or_change_the_quote(): void
    {
        $patient = $this->makePatient();
        $quote = $this->makeApprovedQuote($patient); // total 2088.00
        $svc = app(\App\Services\Facturacion\InvoiceService::class);
        $line = fn (string $price) => [[
            'description' => 'Resina compuesta', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
            'quantity' => '1', 'unit_price' => $price, 'discount_pct' => '0',
            'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
        ]];
        $base = fn (array $items) => [
            'patient_id' => $patient->id, 'quote_id' => $quote->id,
            'branch_id' => (string) \App\Models\Branch::main()->value('id'), 'receiver_mode' => 'patient',
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04', 'items' => $items,
        ];

        // 1800 + 16% = 2088.00 (igual al presupuesto) -> ok. 1801 -> excede.
        try {
            $svc->create($base($line('1801')));
            $this->fail('Debio rechazar una factura mayor al presupuesto');
        } catch (\App\Services\Facturacion\BillingException $e) {
            $this->assertStringContainsString('excede', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());

        $inv = $svc->create($base($line('1800')));
        $this->assertSame('2088.00', (string) $inv->total);

        // Una segunda factura vigente del mismo presupuesto no se permite.
        $this->expectException(\App\Services\Facturacion\BillingException::class);
        $svc->create($base($line('1800')));
    }

    public function test_draft_linked_to_a_quote_keeps_its_patient_and_ceiling(): void
    {
        $patient = $this->makePatient();
        $quote = $this->makeApprovedQuote($patient);
        $svc = app(\App\Services\Facturacion\InvoiceService::class);
        $items = [[
            'description' => 'Resina', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48', 'quantity' => '1',
            'unit_price' => '1800', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
        ]];
        $data = [
            'patient_id' => $patient->id, 'quote_id' => $quote->id, 'branch_id' => (string) \App\Models\Branch::main()->value('id'),
            'receiver_mode' => 'patient', 'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04', 'items' => $items,
        ];
        $inv = $svc->create($data);

        // Editar el borrador: no puede cambiarse a otro paciente ni subir el monto.
        $other = $this->makePatient();
        $caught = 0;
        foreach ([['patient_id' => $other->id], ['items' => [array_merge($items[0], ['unit_price' => '9999'])]]] as $change) {
            try {
                $svc->updateDraft($inv, array_replace($data, $change));
            } catch (\App\Services\Facturacion\BillingException $e) {
                $caught++;
            }
        }
        $this->assertSame(2, $caught);

        // Editarlo sin salirse de lo permitido sí funciona (no choca con su propia factura).
        $ok = $svc->updateDraft($inv, array_replace($data, ['cfdi_use' => 'G03']));
        $this->assertSame('G03', $ok->cfdi_use);
    }

    public function test_ppd_payment_creates_a_consistent_complement_without_fiscal_uuid(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000', ['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '99']); // 1160.00
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '400.00']);
        $this->pay($inv, ['amount' => '300.00']);

        $rows = \App\Models\PaymentComplement::orderBy('installment_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows->pluck('installment_number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['1160.00', '760.00'], $rows->pluck('previous_balance')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['400.00', '300.00'], $rows->pluck('paid_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['760.00', '460.00'], $rows->pluck('remaining_balance')->map(fn ($v) => (string) $v)->all());
        foreach ($rows as $r) {
            $complement = Invoice::findOrFail($r->complement_invoice_id);
            $this->assertSame('P', $complement->cfdi_type->value);
            $this->assertNull($complement->uuid_fiscal);
            $this->assertSame('0.00', (string) $complement->total);
        }
    }
}

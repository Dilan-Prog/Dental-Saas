<?php

namespace Tests\Feature\Facturacion;

use App\Enums\CollectionStatus;
use App\Models\FinancialMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\PaymentComplement;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\PacResponseHandler;
use App\Services\Facturacion\PaymentService;
use App\Models\PacRequest;
use Illuminate\Support\Str;
use Tests\Feature\Facturacion\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use BuildsBillingFixtures;

    private function pay(Invoice $invoice, array $override = [])
    {
        return $this->post(route('pagos.store', $invoice), $this->paymentPayload($invoice, $override));
    }

    // ------------------------------------------------------------------ pago desde factura

    public function test_partial_then_full_payment_updates_collection_status(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000'); // total 1160
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '400.00'])->assertRedirect(route('facturacion.show', $inv))->assertSessionHas('success');
        $inv->refresh();
        $this->assertSame(CollectionStatus::PagoParcial, $inv->collection_status);
        $this->assertSame('760.00', $inv->balance());

        $this->pay($inv, ['amount' => '760.00'])->assertSessionHas('success');
        $inv->refresh();
        $this->assertSame(CollectionStatus::Pagada, $inv->collection_status);
        $this->assertSame('0.00', $inv->balance());

        // Pagada: ya no admite cobros (el monto excede el saldo de $0).
        $this->pay($inv, ['amount' => '1.00'])->assertSessionHasErrors('amount', null, 'pago');
        $this->assertSame(2, Payment::count());
    }

    public function test_payment_creates_its_financial_income_movement_exactly_once(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '1160.00', 'method' => 'tarjeta']);

        $payment = Payment::firstOrFail();
        $mov = FinancialMovement::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('ingreso', $mov->direction->value);
        $this->assertSame('1160.00', $mov->amount);
        $this->assertSame('tarjeta', $mov->method->value);
        $this->assertNull($mov->cash_session_id, 'Solo el efectivo se liga a una sesion de caja');
        $this->assertSame(1, FinancialMovement::where('payment_id', $payment->id)->count());
    }

    public function test_amount_cannot_exceed_balance_or_be_non_positive(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '1160.01'])->assertSessionHasErrors('amount', null, 'pago');
        $this->pay($inv, ['amount' => '0'])->assertSessionHasErrors('amount', null, 'pago');
        $this->pay($inv, ['amount' => ''])->assertSessionHasErrors('amount', null, 'pago');
        $this->assertSame(0, Payment::count());
    }

    public function test_money_with_symbols_is_accepted(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '$1,160.00'])->assertSessionHas('success');
        $this->assertSame('1160.00', Payment::firstOrFail()->amount);
    }

    public function test_invalid_method_and_future_date_are_rejected(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['method' => 'cheque'])->assertSessionHasErrors('method', null, 'pago');
        $this->pay($inv, ['paid_at' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('paid_at', null, 'pago');
        $this->pay($inv, ['paid_at' => 'no-es-fecha'])->assertSessionHasErrors('paid_at', null, 'pago');
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_date_cannot_precede_invoice_creation(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['paid_at' => now()->subDays(10)->toDateString(), 'amount' => '100'])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());
    }

    public function test_idempotency_key_prevents_duplicate_payments(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $payload = $this->paymentPayload($inv, ['amount' => '300.00']);

        $this->post(route('pagos.store', $inv), $payload)->assertSessionHas('success');
        $this->post(route('pagos.store', $inv), $payload)->assertSessionHas('info');

        $this->assertSame(1, Payment::count());
        $this->assertSame('860.00', $inv->fresh()->balance());
    }

    public function test_transfer_requires_a_unique_reference(): void
    {
        $a = $this->makeIssued($this->makePatient(true, 'A'), '1000');
        $b = $this->makeIssued($this->makePatient(true, 'B'), '1000');
        $this->actingAsAdmin();

        $this->pay($a, ['method' => 'transferencia', 'reference' => ''])->assertSessionHasErrors('reference', null, 'pago');
        $this->pay($a, ['method' => 'transferencia', 'reference' => 'SPEI-1', 'amount' => '100'])->assertSessionHas('success');
        $this->pay($b, ['method' => 'transferencia', 'reference' => 'SPEI-1', 'amount' => '100'])->assertSessionHasErrors('reference', null, 'pago');
        $this->assertSame(1, Payment::count());
    }

    public function test_cash_requires_an_open_cash_session(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();

        $this->pay($inv, ['method' => 'efectivo', 'reference' => '', 'amount' => '500'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'caja abierta'));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, FinancialMovement::count());
    }

    public function test_cash_payment_is_linked_to_the_open_session_and_counts_in_expected_cash(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $session = $this->openCashSession();
        $this->actingAsAdmin();

        $this->pay($inv, ['method' => 'efectivo', 'reference' => '', 'amount' => '500.00'])->assertSessionHas('success');

        $payment = Payment::firstOrFail();
        $this->assertSame($session->id, $payment->cash_session_id);
        $this->assertSame($session->id, FinancialMovement::where('payment_id', $payment->id)->value('cash_session_id'));
        $this->assertSame('1000.00', $session->fresh()->expectedCash()); // 500 apertura + 500 cobro
    }

    public function test_draft_cancelled_and_other_patient_invoices_do_not_accept_payments(): void
    {
        $p = $this->makePatient();
        $draft = $this->makeDraft($p, '1000');
        $this->actingAsAdmin();

        $this->post(route('pagos.store', $draft), $this->paymentPayload($draft, ['amount' => '100']))->assertSessionHas('error');
        $this->assertSame(0, Payment::count());

        // Servicio: factura de otro paciente
        $inv = $this->makeIssued($this->makePatient(true, 'Otro'), '1000');
        $this->expectException(BillingException::class);
        app(PaymentService::class)->register([
            'patient_id' => $p->id, 'method' => 'tarjeta', 'reference' => 'X', 'idempotency_key' => (string) Str::uuid(),
            'applications' => [$inv->id => '100'],
        ]);
    }

    // ------------------------------------------------------------------ pago multiple

    public function test_multi_payment_splits_amount_between_invoices_of_one_patient(): void
    {
        $p = $this->makePatient();
        $a = $this->makeIssued($p, '1000'); // 1160
        $b = $this->makeIssued($p, '2000'); // 2320
        $this->actingAsAdmin();

        $this->get(route('pagos.create', ['patient' => $p->id]))->assertOk()->assertSee($a->internal_folio)->assertSee($b->internal_folio);

        $this->post(route('pagos.storeMulti'), [
            'patient_id' => $p->id, 'method' => 'transferencia', 'reference' => 'SPEI-MULTI', 'idempotency_key' => (string) Str::uuid(),
            'applications' => [$a->id => '1160.00', $b->id => '1000.00'],
        ])->assertSessionHas('success');

        $payment = Payment::firstOrFail();
        $this->assertSame('2160.00', $payment->amount);
        $this->assertSame(2, PaymentApplication::where('payment_id', $payment->id)->count());
        $this->assertSame(CollectionStatus::Pagada, $a->fresh()->collection_status);
        $this->assertSame(CollectionStatus::PagoParcial, $b->fresh()->collection_status);
        $this->assertSame('1320.00', $b->fresh()->balance());
        $this->assertSame('2160.00', FinancialMovement::where('payment_id', $payment->id)->value('amount'));
    }

    public function test_multi_payment_validations(): void
    {
        $p = $this->makePatient();
        $a = $this->makeIssued($p, '1000');
        $this->actingAsAdmin();
        $base = ['patient_id' => $p->id, 'method' => 'tarjeta', 'reference' => 'A', 'idempotency_key' => (string) Str::uuid()];

        $this->post(route('pagos.storeMulti'), $base + ['applications' => []])->assertSessionHasErrors('applications');
        $this->post(route('pagos.storeMulti'), $base + ['applications' => [$a->id => '5000']])->assertSessionHas('error');
        $this->post(route('pagos.storeMulti'), $base + ['applications' => [$a->id => '-5']])->assertSessionHasErrors("applications.{$a->id}");
        $this->assertSame(0, Payment::count());
    }

    public function test_invoice_of_another_patient_cannot_be_paid_in_a_multi_payment(): void
    {
        $p = $this->makePatient(true, 'P');
        $q = $this->makePatient(true, 'Q');
        $inv = $this->makeIssued($q, '1000');
        $this->actingAsAdmin();

        $this->post(route('pagos.storeMulti'), [
            'patient_id' => $p->id, 'method' => 'tarjeta', 'reference' => 'A', 'idempotency_key' => (string) Str::uuid(),
            'applications' => [$inv->id => '100'],
        ])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());
    }

    // ------------------------------------------------------------------ reversion

    public function test_reversal_restores_balance_and_records_an_opposite_movement(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->pay($inv, ['amount' => '1160.00']);
        $payment = Payment::firstOrFail();

        $this->post(route('pagos.reverse', $payment), ['reason' => ''])->assertSessionHasErrors('reason', null, 'reversion_' . $payment->id);
        $this->post(route('pagos.reverse', $payment), ['reason' => 'Cobro duplicado por error'])->assertSessionHas('success');

        $this->assertSame('revertido', $payment->fresh()->status->value);
        $this->assertSame(CollectionStatus::Pendiente, $inv->fresh()->collection_status);
        $this->assertSame('1160.00', $inv->fresh()->balance());
        $this->assertNotNull(PaymentApplication::firstOrFail()->reversed_at);
        $this->assertDatabaseHas('payment_reversals', ['payment_id' => $payment->id, 'reason' => 'Cobro duplicado por error']);

        $original = FinancialMovement::where('payment_id', $payment->id)->firstOrFail();
        $rev = FinancialMovement::where('reversed_of_id', $original->id)->firstOrFail();
        $this->assertSame('egreso', $rev->direction->value);
        $this->assertSame($original->amount, $rev->amount);
        $this->assertNull($rev->payment_id);

        // No se revierte dos veces y el saldo vuelve a poder cobrarse.
        $this->post(route('pagos.reverse', $payment), ['reason' => 'Otra vez más'])->assertSessionHas('error');
        $this->pay($inv, ['amount' => '1160.00'])->assertSessionHas('success');
    }

    public function test_cash_reversal_requires_open_session_and_nets_expected_cash(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $session = $this->openCashSession();
        $this->actingAsAdmin();
        $this->pay($inv, ['method' => 'efectivo', 'reference' => '', 'amount' => '500.00']);
        $payment = Payment::firstOrFail();

        $this->post(route('pagos.reverse', $payment), ['reason' => 'Cliente se arrepintio'])->assertSessionHas('success');
        $this->assertSame('500.00', $session->fresh()->expectedCash(), 'Cobro y reversion se netean en la caja');
    }

    // ------------------------------------------------------------------ PPD / complementos

    public function test_ppd_payment_creates_a_payment_complement_draft(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000', ['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '99']);
        $this->actingAsAdmin();

        $this->pay($inv, ['amount' => '500.00', 'method' => 'transferencia', 'reference' => 'SPEI-PPD']);
        $this->pay($inv, ['amount' => '660.00', 'method' => 'transferencia', 'reference' => 'SPEI-PPD-2']);

        $rows = PaymentComplement::where('related_invoice_id', $inv->id)->orderBy('installment_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows->pluck('installment_number')->all());
        $this->assertSame('1160.00', $rows[0]->previous_balance);
        $this->assertSame('500.00', $rows[0]->paid_amount);
        $this->assertSame('660.00', $rows[0]->remaining_balance);
        $this->assertSame('660.00', $rows[1]->previous_balance);
        $this->assertSame('0.00', $rows[1]->remaining_balance);

        $comp = Invoice::findOrFail($rows[0]->complement_invoice_id);
        $this->assertSame('P', $comp->cfdi_type->value);
        $this->assertSame('borrador', $comp->fiscal_status->value);
        $this->assertSame('0.00', $comp->total);
        $this->assertNull($comp->uuid_fiscal);
        $this->get(route('facturacion.show', $comp))->assertOk()->assertSee('Documentos relacionados');
    }

    public function test_pue_payment_does_not_create_complements(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->pay($inv, ['amount' => '1160']);

        $this->assertSame(0, PaymentComplement::count());
    }

    public function test_reversal_is_blocked_when_the_complement_is_already_stamped(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000', ['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '99']);
        $this->actingAsAdmin();
        $this->pay($inv, ['amount' => '500.00', 'method' => 'transferencia', 'reference' => 'SPEI-C']);
        $payment = Payment::firstOrFail();
        $comp = Invoice::findOrFail(PaymentComplement::firstOrFail()->complement_invoice_id);

        // Simula que el PAC ya timbro el complemento.
        $comp->forceFill(['fiscal_status' => 'timbrada', 'uuid_fiscal' => (string) Str::uuid()])->save();

        $this->post(route('pagos.reverse', $payment), ['reason' => 'Intento de reversion'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'timbrado'));
        $this->assertSame('registrado', $payment->fresh()->status->value);
    }

    // ------------------------------------------------------------------ coherencia con cancelacion

    public function test_invoice_with_payments_cannot_be_cancelled_until_payments_are_reversed(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->pay($inv, ['amount' => '100']);

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02'])->assertSessionHas('error');
        $this->assertSame('pendiente_timbrado', $inv->fresh()->fiscal_status->value);

        $this->post(route('pagos.reverse', Payment::firstOrFail()), ['reason' => 'Se devuelve el dinero']);
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02'])->assertSessionHas('success');
        $this->assertSame('cancelada', $inv->fresh()->fiscal_status->value);
    }

    public function test_cancelled_invoice_does_not_accept_payments(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $this->actingAsAdmin();
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02']);

        $this->pay($inv, ['amount' => '100'])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());
    }

    public function test_database_triggers_back_up_the_balance_rule(): void
    {
        $inv = $this->makeIssued($this->makePatient(), '1000');
        $payment = new Payment([
            'branch_id' => $inv->branch_id, 'patient_id' => $inv->patient_id, 'paid_at' => now(), 'amount' => '5000.00',
            'currency' => 'MXN', 'method' => 'tarjeta', 'status' => 'registrado', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $payment->save();

        $this->expectException(\Illuminate\Database\QueryException::class);
        (new PaymentApplication(['payment_id' => $payment->id, 'invoice_id' => $inv->id, 'applied_amount' => '2000.00', 'applied_at' => now()]))->save();
    }
}

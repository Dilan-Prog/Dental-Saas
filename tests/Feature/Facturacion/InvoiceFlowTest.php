<?php

namespace Tests\Feature\Facturacion;

use App\Enums\FiscalStatus;
use App\Models\Invoice;
use App\Models\PacRequest;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\InvoiceService;
use Tests\Feature\Facturacion\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class InvoiceFlowTest extends TestCase
{
    use BuildsBillingFixtures;

    private function payload(array $override = []): array
    {
        $patient = $override['__patient'] ?? $this->makePatient();
        unset($override['__patient']);

        return array_merge([
            'patient_id' => $patient->id,
            'receiver_mode' => 'patient',
            'branch_id' => $this->mainBranchId(),
            'cfdi_use' => 'D01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '04',
            'items' => [[
                'description' => 'Limpieza dental', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
                'quantity' => '1', 'unit_price' => '12000', 'discount_pct' => '0',
                'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
            ]],
        ], $override);
    }

    // ------------------------------------------------------------------ acceso / permisos

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('facturacion.index'))->assertRedirect(route('login'));
    }

    public function test_doctor_without_billing_permission_gets_403(): void
    {
        $this->actingAsDoctor();
        $this->get(route('facturacion.index'))->assertForbidden();
    }

    public function test_receptionist_can_view_and_pay_but_not_create_or_cancel(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsReceptionist();

        $this->get(route('facturacion.index'))->assertOk();
        $this->get(route('facturacion.show', $inv))->assertOk()->assertSee('Registrar pago')->assertDontSee('Cancelar CFDI');
        $this->get(route('facturacion.create'))->assertForbidden();
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02'])->assertForbidden();
    }

    // ------------------------------------------------------------------ listado

    public function test_index_lists_invoices_with_kpis_and_filters(): void
    {
        $this->configureIssuer();
        $a = $this->makeIssued($this->makePatient(true, 'Ana Uno'), '1000');
        $b = $this->makeDraft($this->makePatient(true, 'Beto Dos'), '500');
        $this->actingAsAdmin();

        $this->get(route('facturacion.index'))
            ->assertOk()->assertSee($a->internal_folio)->assertSee($b->internal_folio)
            ->assertSee('Facturado del mes')->assertSee('Por cobrar')->assertSee('Cobrado del mes')
            ->assertSee('$1,160.00');

        $this->get(route('facturacion.index', ['fiscal_status' => 'borrador']))
            ->assertOk()->assertSee($b->internal_folio)->assertDontSee($a->internal_folio);

        $this->get(route('facturacion.index', ['q' => 'Ana Uno']))
            ->assertOk()->assertSee($a->internal_folio)->assertDontSee($b->internal_folio);
    }

    public function test_index_rejects_invalid_filters(): void
    {
        $this->actingAsAdmin();

        $this->get(route('facturacion.index', ['fiscal_status' => 'inventado']))->assertSessionHasErrors('fiscal_status');
        $this->get(route('facturacion.index', ['from' => '2026-05-10', 'to' => '2026-05-01']))->assertSessionHasErrors('to');
    }

    public function test_csv_export_downloads_rows(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $res = $this->get(route('facturacion.export'));
        $res->assertOk();
        $this->assertStringContainsString($inv->internal_folio, $res->streamedContent());
    }

    // ------------------------------------------------------------------ alta y validaciones

    public function test_store_creates_a_draft_with_server_side_totals(): void
    {
        $this->actingAsAdmin();

        $this->post(route('facturacion.store'), $this->payload())->assertRedirect();

        $inv = Invoice::firstOrFail();
        $this->assertSame(FiscalStatus::Borrador, $inv->fiscal_status);
        $this->assertSame('12000.00', $inv->subtotal);
        $this->assertSame('1920.00', $inv->tax_total);
        $this->assertSame('13920.00', $inv->total);
        $this->assertMatchesRegularExpression('/^F-\d{4}$/', $inv->internal_folio);
        $this->assertNull($inv->uuid_fiscal);
        $this->assertSame(1, $inv->items()->count());
        $this->assertSame('DOSV850312KT4', $inv->receiver_snapshot['rfc']);
    }

    public function test_store_requires_items_and_catalog_values(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->post(route('facturacion.store'), $this->payload(['__patient' => $p, 'items' => []]))
            ->assertSessionHasErrors('items');

        $bad = $this->payload(['__patient' => $p, 'cfdi_use' => 'ZZZ', 'cfdi_payment_method' => 'XXX', 'cfdi_payment_form' => '77']);
        $this->post(route('facturacion.store'), $bad)->assertSessionHasErrors(['cfdi_use', 'cfdi_payment_method', 'cfdi_payment_form']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_store_validates_line_fields(): void
    {
        $this->actingAsAdmin();
        $items = [[
            'description' => '', 'sat_product_key' => '123', 'sat_unit_key' => 'ZZZ', 'quantity' => '0',
            'unit_price' => '-5', 'discount_pct' => '150', 'tax_object_code' => '09', 'tax_profile_id' => null,
        ]];

        $this->post(route('facturacion.store'), $this->payload(['items' => $items]))
            ->assertSessionHasErrors([
                'items.0.description', 'items.0.sat_product_key', 'items.0.sat_unit_key', 'items.0.quantity',
                'items.0.unit_price', 'items.0.discount_pct', 'items.0.tax_object_code',
            ]);
    }

    public function test_tax_profile_is_required_with_tax_object_02_and_forbidden_with_01(): void
    {
        $this->actingAsAdmin();
        $line = fn (array $o) => [array_merge([
            'description' => 'X', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48', 'quantity' => '1',
            'unit_price' => '100', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax_profile_id' => null,
        ], $o)];

        $this->post(route('facturacion.store'), $this->payload(['items' => $line([])]))
            ->assertSessionHasErrors('items.0.tax_profile_id');
        $this->post(route('facturacion.store'), $this->payload(['items' => $line(['tax_object_code' => '01', 'tax_profile_id' => $this->iva()->id])]))
            ->assertSessionHasErrors('items.0.tax_profile_id');
    }

    public function test_ppd_requires_form_99_and_pue_forbids_it(): void
    {
        $this->actingAsAdmin();

        $this->post(route('facturacion.store'), $this->payload(['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '04']))
            ->assertSessionHasErrors('cfdi_payment_form');
        $this->post(route('facturacion.store'), $this->payload(['cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '99']))
            ->assertSessionHasErrors('cfdi_payment_form');
        $this->post(route('facturacion.store'), $this->payload(['cfdi_payment_method' => 'PPD', 'cfdi_payment_form' => '99']))
            ->assertSessionHasNoErrors();
    }

    public function test_money_inputs_with_symbols_are_normalized(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = '$1,500.50';

        $this->post(route('facturacion.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame('1500.50', Invoice::firstOrFail()->subtotal);
    }

    public function test_cross_organization_ids_are_rejected(): void
    {
        $this->actingAsAdmin();

        $this->post(route('facturacion.store'), $this->payload(['branch_id' => '00000000-0000-4000-8000-000000000000']))
            ->assertSessionHasErrors('branch_id');
        $this->post(route('facturacion.store'), $this->payload(['patient_id' => '00000000-0000-4000-8000-000000000000']))
            ->assertSessionHasErrors('patient_id');
    }

    public function test_draft_can_be_edited_and_deleted_but_issued_cannot(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $draft = $this->makeDraft($p, '1000');

        $edit = $this->payload(['__patient' => $p]);
        $edit['items'][0]['unit_price'] = '2000';
        $this->put(route('facturacion.update', $draft), $edit)->assertRedirect(route('facturacion.show', $draft));
        $this->assertSame('2320.00', $draft->fresh()->total);
        $this->assertSame(1, $draft->items()->count());

        $this->delete(route('facturacion.destroy', $draft))->assertRedirect(route('facturacion.index'));
        $this->assertNull(Invoice::find($draft->id));

        $issued = $this->makeIssued($this->makePatient());
        $this->get(route('facturacion.edit', $issued))->assertRedirect(route('facturacion.show', $issued));
        $this->put(route('facturacion.update', $issued), $edit)->assertSessionHas('error');
        $this->delete(route('facturacion.destroy', $issued))->assertSessionHas('error');
        $this->assertNotNull(Invoice::find($issued->id));
    }

    // ------------------------------------------------------------------ desde presupuesto

    public function test_from_quote_prefills_and_links_the_quote(): void
    {
        $p = $this->makePatient();
        $quote = $this->makeApprovedQuote($p);
        $this->actingAsAdmin();

        $this->get(route('facturacion.fromQuote', $quote))->assertOk()->assertSee('Resina compuesta')->assertSee($quote->folio);

        $payload = $this->payload(['__patient' => $p, 'quote_id' => $quote->id]);
        $payload['items'] = [[
            'description' => 'Resina compuesta', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48', 'quantity' => '2',
            'unit_price' => '1000', 'discount_pct' => '10', 'tax_object_code' => '02', 'tax_profile_id' => $this->iva()->id,
        ]];
        $this->post(route('facturacion.store'), $payload)->assertSessionHasNoErrors();

        $inv = Invoice::firstOrFail();
        $this->assertSame($quote->id, $inv->quote_id);
        $this->assertSame($quote->total, $inv->total, 'La factura debe coincidir con el total del presupuesto');
    }

    public function test_quote_must_be_approved_belong_to_patient_and_not_be_invoiced_twice(): void
    {
        $p = $this->makePatient();
        $draftQuote = $this->makeApprovedQuote($p, 'borrador');
        $this->actingAsAdmin();

        $this->get(route('facturacion.fromQuote', $draftQuote))->assertRedirect()->assertSessionHas('error');

        $quote = $this->makeApprovedQuote($p);
        $other = $this->makePatient(true, 'Otro');
        $this->expectException(BillingException::class);
        try {
            $this->makeDraft($other, '1000', ['quote_id' => $quote->id]);
        } finally {
            $this->assertSame(0, Invoice::where('quote_id', $quote->id)->count());
        }
    }

    public function test_quote_cannot_have_two_active_invoices(): void
    {
        $p = $this->makePatient();
        $quote = $this->makeApprovedQuote($p);
        $this->makeDraft($p, '1000', ['quote_id' => $quote->id]);

        $this->expectException(BillingException::class);
        $this->makeDraft($p, '1000', ['quote_id' => $quote->id]);
    }

    // ------------------------------------------------------------------ emision

    public function test_issue_is_blocked_without_issuer_configuration(): void
    {
        $this->actingAsAdmin();
        $draft = $this->makeDraft($this->makePatient());

        $this->post(route('facturacion.emit', $draft))->assertSessionHas('error')->assertSessionHas('emit_errors');
        $errors = session('emit_errors');
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'RFC')));
        $this->assertSame(FiscalStatus::Borrador, $draft->fresh()->fiscal_status);
        $this->assertSame(0, PacRequest::count());
    }

    public function test_issue_is_blocked_without_valid_receiver_data(): void
    {
        $this->configureIssuer();
        $this->actingAsAdmin();
        $noProfile = $this->makeDraft($this->makePatient(false));

        $this->post(route('facturacion.emit', $noProfile))->assertSessionHas('emit_errors');
        $this->assertTrue(collect(session('emit_errors'))->contains(fn ($e) => str_contains($e, 'receptor')));
        $this->assertSame(FiscalStatus::Borrador, $noProfile->fresh()->fiscal_status);
    }

    public function test_issue_rejects_regime_and_use_incompatible_with_rfc(): void
    {
        $this->configureIssuer();
        $moral = $this->makePatient(true, 'Empresa', 'CUS200115AB3'); // RFC de persona moral con regimen 612 (persona fisica)
        $draft = $this->makeDraft($moral);

        try {
            app(InvoiceService::class)->issue($draft);
            $this->fail('Debio bloquear regimen/uso incompatibles.');
        } catch (BillingException $e) {
            $joined = implode(' | ', $e->errors);
            $this->assertStringContainsString('regimen fiscal del receptor', $joined);
            $this->assertStringContainsString('uso de CFDI', $joined);
        }
    }

    public function test_issue_queues_a_pac_request_without_inventing_a_fiscal_uuid(): void
    {
        $this->configureIssuer();
        $this->actingAsAdmin();
        $draft = $this->makeDraft($this->makePatient());

        $this->post(route('facturacion.emit', $draft))->assertSessionHas('success');

        $inv = $draft->fresh();
        $this->assertSame(FiscalStatus::PendienteTimbrado, $inv->fiscal_status);
        $this->assertNull($inv->uuid_fiscal);
        $this->assertNull($inv->stamped_at);
        $this->assertNull($inv->xml_storage_key);
        $this->assertNotNull($inv->issued_at);

        $req = PacRequest::firstOrFail();
        $this->assertSame($inv->id, $req->invoice_id);
        $this->assertSame('timbrado', $req->kind->value);
        $this->assertSame('pendiente', $req->status->value);
        $this->assertSame(1, $req->attempt_number);

        // No se puede emitir dos veces.
        $this->post(route('facturacion.emit', $inv))->assertSessionHas('emit_errors');
        $this->assertSame(1, PacRequest::count());
    }

    // ------------------------------------------------------------------ vistas

    public function test_show_print_and_xml_routes(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $this->get(route('facturacion.show', $inv))->assertOk()->assertSee($inv->internal_folio)->assertSee('se asigna al timbrar');
        $this->get(route('facturacion.print', $inv))->assertOk()->assertSee('SIN TIMBRAR');
        $this->get(route('facturacion.xml', $inv))->assertSessionHas('error');
    }

    public function test_create_form_renders_for_admin(): void
    {
        $p = $this->makePatient();
        $this->actingAsAdmin();

        $this->get(route('facturacion.create', ['patient' => $p->id]))->assertOk()->assertSee('Conceptos')->assertSee('Guardar y emitir');
    }
}

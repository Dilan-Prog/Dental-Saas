<?php

namespace Tests\Feature\Facturacion;

use App\Enums\FiscalStatus;
use App\Models\Invoice;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceRelation;
use App\Models\PacRequest;
use App\Services\Facturacion\BillingException;
use App\Services\Facturacion\PacResponseHandler;
use Illuminate\Support\Str;
use Tests\Feature\Facturacion\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class PacAndCancellationTest extends TestCase
{
    use BuildsBillingFixtures;

    private function stamp(Invoice $inv, ?string $uuid = null): Invoice
    {
        $req = PacRequest::where('invoice_id', $inv->id)->where('kind', 'timbrado')->firstOrFail();
        (new PacResponseHandler())->apply($req, [
            'success' => true, 'code' => '200', 'uuid_fiscal' => $uuid ?? (string) Str::uuid(),
            'xml_storage_key' => 'cfdi/' . $inv->id . '.xml',
        ]);

        return $inv->fresh();
    }

    // ------------------------------------------------------------------ respuesta de timbrado

    public function test_real_pac_response_stamps_the_invoice(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $uuid = (string) Str::uuid();

        $inv = $this->stamp($inv, $uuid);

        $this->assertSame(FiscalStatus::Timbrada, $inv->fiscal_status);
        $this->assertSame($uuid, $inv->uuid_fiscal);
        $this->assertNotNull($inv->stamped_at);
        $this->assertSame(1, $inv->fiscal_folio);
        $this->assertSame('exitoso', PacRequest::firstOrFail()->status->value);
    }

    public function test_fiscal_folios_increment_per_series(): void
    {
        $a = $this->stamp($this->makeIssued($this->makePatient(true, 'A')));
        $b = $this->stamp($this->makeIssued($this->makePatient(true, 'B')));

        $this->assertSame(1, $a->fiscal_folio);
        $this->assertSame(2, $b->fiscal_folio);
        $this->assertSame('A', $b->series);
    }

    public function test_pac_response_is_idempotent_and_rejects_invalid_or_duplicate_uuid(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $req = PacRequest::firstOrFail();
        $uuid = (string) Str::uuid();

        $this->stamp($inv, $uuid);
        (new PacResponseHandler())->apply($req, ['success' => true, 'uuid_fiscal' => (string) Str::uuid()]); // ya exitoso: se ignora
        $this->assertSame($uuid, $inv->fresh()->uuid_fiscal);

        $other = $this->makeIssued($this->makePatient(true, 'Otro'));
        $this->expectException(BillingException::class);
        (new PacResponseHandler())->apply(PacRequest::where('invoice_id', $other->id)->firstOrFail(), ['success' => true, 'uuid_fiscal' => $uuid]);
    }

    public function test_pac_response_without_uuid_is_rejected(): void
    {
        $inv = $this->makeIssued($this->makePatient());

        $this->expectException(BillingException::class);
        try {
            (new PacResponseHandler())->apply(PacRequest::firstOrFail(), ['success' => true, 'uuid_fiscal' => 'no-es-uuid']);
        } finally {
            $this->assertNull($inv->fresh()->uuid_fiscal);
        }
    }

    public function test_pac_error_marks_invoice_with_error_and_allows_retry(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        (new PacResponseHandler())->apply(PacRequest::firstOrFail(), ['success' => false, 'code' => '301', 'message' => 'RFC del receptor no existe']);

        $this->assertSame(FiscalStatus::Error, $inv->fresh()->fiscal_status);
        $this->assertNull($inv->fresh()->uuid_fiscal);

        $this->actingAsAdmin();
        $this->post(route('facturacion.emit', $inv))->assertSessionHas('success');
        $this->assertSame(2, PacRequest::where('invoice_id', $inv->id)->max('attempt_number'));
        $this->assertSame(FiscalStatus::PendienteTimbrado, $inv->fresh()->fiscal_status);
    }

    public function test_artisan_command_applies_the_response(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $req = PacRequest::firstOrFail();
        $uuid = (string) Str::uuid();

        $this->artisan('facturacion:pac-respuesta', ['pac_request' => $req->id, '--status' => 'ok', '--uuid' => $uuid])->assertSuccessful();
        $this->assertSame($uuid, $inv->fresh()->uuid_fiscal);

        $this->artisan('facturacion:pac-respuesta', ['pac_request' => $req->id, '--status' => 'ok', '--uuid' => 'x'])->assertSuccessful(); // idempotente
        $this->artisan('facturacion:pac-respuesta', ['pac_request' => (string) Str::uuid()])->assertFailed();
    }

    // ------------------------------------------------------------------ cancelacion

    public function test_unstamped_invoice_is_cancelled_locally_and_pending_requests_are_voided(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '03', 'detail' => 'No se realizo'])->assertSessionHas('success');

        $this->assertSame(FiscalStatus::Cancelada, $inv->fresh()->fiscal_status);
        $c = InvoiceCancellation::firstOrFail();
        $this->assertSame('aceptada', $c->status->value);
        $this->assertSame('03', $c->reason_code);
        $this->assertSame('error', PacRequest::firstOrFail()->status->value);
    }

    public function test_stamped_invoice_cancellation_goes_through_the_pac(): void
    {
        $inv = $this->stamp($this->makeIssued($this->makePatient()));
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02'])->assertSessionHas('success');

        $this->assertSame(FiscalStatus::CancelacionSolicitada, $inv->fresh()->fiscal_status);
        $cancelReq = PacRequest::where('kind', 'cancelacion')->firstOrFail();
        $c = InvoiceCancellation::firstOrFail();
        $this->assertSame('en_proceso', $c->status->value);
        $this->assertSame($cancelReq->id, $c->pac_request_id);

        // El SAT acepta la cancelacion.
        (new PacResponseHandler())->apply($cancelReq, ['success' => true, 'cancellation_status' => 'aceptada']);
        $this->assertSame(FiscalStatus::Cancelada, $inv->fresh()->fiscal_status);
        $this->assertSame('aceptada', $c->fresh()->status->value);

        // Ya no se puede volver a cancelar.
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02'])->assertSessionHas('error');
    }

    public function test_rejected_cancellation_returns_the_invoice_to_stamped(): void
    {
        $inv = $this->stamp($this->makeIssued($this->makePatient()));
        $this->actingAsAdmin();
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '02']);

        (new PacResponseHandler())->apply(PacRequest::where('kind', 'cancelacion')->firstOrFail(), [
            'success' => false, 'cancellation_status' => 'rechazada', 'message' => 'El receptor rechazo la cancelacion',
        ]);

        $this->assertSame(FiscalStatus::Timbrada, $inv->fresh()->fiscal_status);
        $this->assertSame('rechazada', InvoiceCancellation::firstOrFail()->status->value);
    }

    public function test_reason_01_requires_a_valid_substitute_of_the_same_patient(): void
    {
        $p = $this->makePatient();
        $inv = $this->makeIssued($p);
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '01'])->assertSessionHasErrors('substitute_invoice_id', null, 'cancelacion');

        $otherPatient = $this->makeIssued($this->makePatient(true, 'Otro'));
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '01', 'substitute_invoice_id' => $otherPatient->id])->assertSessionHas('error');
        $this->assertSame(FiscalStatus::PendienteTimbrado, $inv->fresh()->fiscal_status);

        $substitute = $this->makeIssued($p);
        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '01', 'substitute_invoice_id' => $substitute->id])->assertSessionHas('success');

        $this->assertSame(FiscalStatus::Cancelada, $inv->fresh()->fiscal_status);
        $this->assertSame($substitute->id, InvoiceCancellation::firstOrFail()->substitute_invoice_id);
        $this->assertDatabaseHas('invoice_relations', ['invoice_id' => $substitute->id, 'related_invoice_id' => $inv->id, 'relation_type_code' => '04']);
    }

    public function test_cancellation_reason_must_be_in_the_sat_catalog(): void
    {
        $inv = $this->makeIssued($this->makePatient());
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $inv), ['reason_code' => '09'])->assertSessionHasErrors('reason_code', null, 'cancelacion');
        $this->post(route('facturacion.cancel', $inv), [])->assertSessionHasErrors('reason_code', null, 'cancelacion');
        $this->assertSame(FiscalStatus::PendienteTimbrado, $inv->fresh()->fiscal_status);
    }

    public function test_drafts_cannot_be_cancelled_only_deleted(): void
    {
        $draft = $this->makeDraft($this->makePatient());
        $this->actingAsAdmin();

        $this->post(route('facturacion.cancel', $draft), ['reason_code' => '02'])->assertSessionHas('error');
        $this->assertSame(FiscalStatus::Borrador, $draft->fresh()->fiscal_status);
    }
}

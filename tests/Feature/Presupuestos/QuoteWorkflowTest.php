<?php

namespace Tests\Feature\Presupuestos;

use App\Enums\PlannedTreatmentStatus;
use App\Enums\QuoteStatus;
use App\Models\PatientTreatment;
use App\Models\Quote;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuoteWorkflowTest extends TestCase
{
    use BuildsQuotes;

    public function test_index_lists_filters_and_counts_by_status(): void
    {
        $this->actingAsAdmin();
        $ana = $this->makePatient(['full_name' => 'Ana Robles']);
        $luis = $this->makePatient(['full_name' => 'Luis Prado']);
        $q1 = $this->makeQuote($ana);
        $q2 = $this->makeQuote($luis);
        app(\App\Services\Presupuestos\QuoteService::class)->send($q2, null);

        $this->get(route('presupuestos.index'))->assertOk()->assertSee($q1->folio)->assertSee($q2->folio)
            ->assertSee('Plan de tratamiento y cotizaciones');

        $this->get(route('presupuestos.index', ['estado' => 'enviado']))->assertOk()
            ->assertSee('Luis Prado')->assertDontSee('Ana Robles');
        $this->get(route('presupuestos.index', ['q' => 'Ana']))->assertOk()
            ->assertSee('Ana Robles')->assertDontSee('Luis Prado');
        $this->get(route('presupuestos.index', ['paciente' => $luis->id]))->assertOk()
            ->assertSee('Luis Prado')->assertDontSee('Ana Robles');
        $this->get(route('presupuestos.index', ['desde' => now()->addDay()->toDateString()]))->assertOk()
            ->assertDontSee($q1->folio);
    }

    public function test_index_ignores_malformed_filters(): void
    {
        $this->actingAsAdmin();
        $this->makeQuote();

        $this->get(route('presupuestos.index', ['estado' => 'x', 'desde' => 'ayer', 'hasta' => '2026-13-45', 'paciente' => 'no-uuid']))
            ->assertOk()->assertSee('PRE-0001');
    }

    public function test_index_empty_state(): void
    {
        $this->actingAsAdmin();
        $this->get(route('presupuestos.index'))->assertOk()->assertSee('Aún no hay presupuestos');
    }

    public function test_show_and_print_render_the_document(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote($this->makePatient(['full_name' => 'Valeria Domínguez Soto']));

        $this->get(route('presupuestos.show', $quote))->assertOk()
            ->assertSee($quote->folio)->assertSee('Valeria Domínguez Soto')->assertSee('Historial')->assertSee('Borrador');

        $this->get(route('presupuestos.print', $quote))->assertOk()
            ->assertSee('Presupuesto')->assertSee($quote->folio)->assertSee('Clínica Dental');
    }

    public function test_unknown_quote_returns_404(): void
    {
        $this->actingAsAdmin();
        $this->get(route('presupuestos.show', (string) Str::uuid()))->assertNotFound();
    }

    public function test_draft_can_be_edited_and_totals_are_recalculated(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote();
        $other = $this->makeTreatment(['price' => '2000.00']);

        $this->get(route('presupuestos.edit', $quote))->assertOk()->assertSee('Editar presupuesto '.$quote->folio);

        $payload = $this->quotePayload($quote->patient, $other, ['items' => [['quantity' => '2', 'discount_pct' => '50']]]);
        $this->put(route('presupuestos.update', $quote), $payload)->assertRedirect(route('presupuestos.show', $quote));

        $quote->refresh();
        // 2 x 2000 = 4000 ; -50% = 2000 ; IVA 320 ; total 2320
        $this->assertSame('4000.00', (string) $quote->subtotal);
        $this->assertSame('2000.00', (string) $quote->discount_total);
        $this->assertSame('320.00', (string) $quote->tax_total);
        $this->assertSame('2320.00', (string) $quote->total);
        $this->assertSame(1, $quote->items()->count());
    }

    public function test_only_drafts_are_editable(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote();
        app(\App\Services\Presupuestos\QuoteService::class)->send($quote, null);

        $this->get(route('presupuestos.edit', $quote))->assertRedirect(route('presupuestos.show', $quote))->assertSessionHas('error');

        $before = $quote->fresh()->total;
        $this->put(route('presupuestos.update', $quote), $this->quotePayload(null, null, ['items' => [['unit_price' => '99999']]]))
            ->assertRedirect(route('presupuestos.show', $quote))->assertSessionHas('error');
        $this->assertSame((string) $before, (string) $quote->fresh()->total);
    }

    public function test_send_approve_creates_treatment_plan_once(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $t1 = $this->makeTreatment();
        $t2 = $this->makeTreatment();
        $quote = $this->makeQuote($patient, [
            ['treatment_id' => $t1->id, 'tooth_fdi' => '36', 'quantity' => '1', 'unit_price' => '800', 'discount_pct' => '0'],
            ['treatment_id' => $t2->id, 'tooth_fdi' => '', 'quantity' => '2', 'unit_price' => '800', 'discount_pct' => '0'],
        ]);

        $this->post(route('presupuestos.send', $quote))->assertRedirect(route('presupuestos.show', $quote))->assertSessionHas('success');
        $this->assertSame(QuoteStatus::Enviado, $quote->fresh()->status);
        $this->assertNotNull($quote->fresh()->sent_at);

        $this->post(route('presupuestos.approve', $quote))->assertSessionHas('success');
        $this->assertSame(QuoteStatus::Aprobado, $quote->fresh()->status);

        $plan = PatientTreatment::query()->where('patient_id', $patient->id)->orderBy('tooth_fdi')->get();
        $this->assertCount(2, $plan);
        $this->assertSame([PlannedTreatmentStatus::Planeado, PlannedTreatmentStatus::Planeado], $plan->pluck('status')->all());
        $this->assertEqualsCanonicalizing($quote->items->pluck('id')->all(), $plan->pluck('quote_item_id')->all());
        $this->assertSame(36, $plan->firstWhere('treatment_id', $t1->id)->tooth_fdi);

        // Historial completo: creado -> enviado -> aprobado
        $this->assertSame(['borrador', 'enviado', 'aprobado'], $quote->statusHistory()->pluck('to_status')->map->value->all());

        // Aprobado ya no admite más transiciones.
        $this->post(route('presupuestos.reject', $quote))->assertSessionHas('error');
        $this->post(route('presupuestos.reopen', $quote))->assertSessionHas('error');
        $this->assertSame(2, PatientTreatment::query()->where('patient_id', $patient->id)->count());
    }

    public function test_invalid_transitions_are_refused_without_side_effects(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote();

        $this->post(route('presupuestos.approve', $quote))->assertSessionHas('error');
        $this->post(route('presupuestos.reject', $quote))->assertSessionHas('error');
        $this->post(route('presupuestos.reopen', $quote))->assertSessionHas('error');

        $this->assertSame(QuoteStatus::Borrador, $quote->fresh()->status);
        $this->assertSame(1, $quote->statusHistory()->count());
        $this->assertSame(0, PatientTreatment::query()->count());
    }

    public function test_reject_and_reopen(): void
    {
        $this->actingAsAdmin();
        $service = app(\App\Services\Presupuestos\QuoteService::class);

        $rejected = $this->makeQuote();
        $service->send($rejected, null);
        $this->post(route('presupuestos.reject', $rejected))->assertSessionHas('success');
        $this->assertSame(QuoteStatus::Rechazado, $rejected->fresh()->status);
        $this->assertSame(0, PatientTreatment::query()->count());

        $reopened = $this->makeQuote();
        $service->send($reopened, null);
        $this->post(route('presupuestos.reopen', $reopened))->assertSessionHas('success');
        $this->assertSame(QuoteStatus::Borrador, $reopened->fresh()->status);
        $this->assertNull($reopened->fresh()->sent_at);
    }

    public function test_draft_can_be_deleted_but_not_other_states(): void
    {
        $this->actingAsAdmin();
        $draft = $this->makeQuote();
        $sent = $this->makeQuote();
        app(\App\Services\Presupuestos\QuoteService::class)->send($sent, null);

        $this->delete(route('presupuestos.destroy', $sent))->assertSessionHas('error');
        $this->assertNotNull(Quote::query()->find($sent->id));

        $this->delete(route('presupuestos.destroy', $draft))->assertRedirect(route('presupuestos.index'))->assertSessionHas('success');
        $this->assertNull(Quote::query()->find($draft->id));
        $this->assertSame(0, \DB::table('quote_items')->where('quote_id', $draft->id)->count());
        $this->assertSame(0, \DB::table('quote_status_history')->where('quote_id', $draft->id)->count());
    }

    public function test_receptionist_can_create_but_not_delete(): void
    {
        $this->actingAsReceptionist();
        $quote = $this->makeQuote();

        $this->get(route('presupuestos.index'))->assertOk();
        $this->post(route('presupuestos.store'), $this->quotePayload())->assertSessionHasNoErrors();
        $this->delete(route('presupuestos.destroy', $quote))->assertForbidden();
        $this->assertNotNull(Quote::query()->find($quote->id));
    }

    public function test_doctor_has_access_to_budgets_and_treatment_catalog(): void
    {
        $this->actingAsDoctor();

        $this->get(route('presupuestos.index'))->assertOk();
        $this->get(route('tratamientos.index'))->assertOk();
        $this->delete(route('presupuestos.destroy', $this->makeQuote()))->assertForbidden();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->actingAsReceptionist();
        $this->get(route('tratamientos.index'))->assertForbidden();
    }

    public function test_approval_button_and_invoice_link_depend_on_state(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote();

        $this->get(route('presupuestos.show', $quote))->assertSee('Marcar como enviado')->assertDontSee('Aprobar');

        app(\App\Services\Presupuestos\QuoteService::class)->send($quote, null);
        $this->get(route('presupuestos.show', $quote))->assertSee('Aprobar')->assertSee('Rechazar')->assertSee('Reabrir');
    }

    public function test_foreign_ids_cannot_be_used_through_route_binding(): void
    {
        $this->actingAsAdmin();
        $this->post(route('presupuestos.send', 'no-es-un-uuid'))->assertNotFound();
        $this->delete(route('presupuestos.destroy', (string) Str::uuid()))->assertNotFound();
    }
}

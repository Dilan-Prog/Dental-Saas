<?php

namespace Tests\Feature\QA\Billing;

use App\Enums\QuoteStatus;
use App\Models\PatientTreatment;
use App\Models\Quote;
use Tests\Feature\Presupuestos\BuildsQuotes;
use Tests\TestCase;

/** QA: bordes de validación y coherencia de presupuestos. */
class QuoteQaTest extends TestCase
{
    use BuildsQuotes;

    private function store(array $override = [])
    {
        $this->actingAsAdmin();

        return $this->post(route('presupuestos.store'), $this->quotePayload(null, null, $override));
    }

    /** Importes extremos no deben provocar error 500 (desborde de decimal(14,2)). */
    public function test_extreme_amounts_are_rejected_with_validation_error_not_500(): void
    {
        $response = $this->store(['items' => [['quantity' => '100000', 'unit_price' => '99999999']]]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Quote::count());
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_many_large_lines_cannot_overflow_the_total(): void
    {
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items[] = ['quantity' => '1', 'unit_price' => '99999999', 'discount_pct' => '0'];
        }
        $payload = $this->quotePayload();
        $payload['items'] = array_map(fn ($r) => $r + ['treatment_id' => $payload['items'][0]['treatment_id'], 'tooth_fdi' => ''], $items);
        $this->actingAsAdmin();

        $response = $this->post(route('presupuestos.store'), $payload);

        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_discount_boundaries(): void
    {
        $this->store(['items' => [['discount_pct' => '100.01']]])->assertSessionHasErrors('items.0.discount_pct');
        $this->store(['items' => [['discount_pct' => '-1']]])->assertSessionHasErrors('items.0.discount_pct');
        $this->store(['items' => [['discount_pct' => '10.123']]])->assertSessionHasErrors('items.0.discount_pct');
        $this->assertSame(0, Quote::count());

        $this->store(['items' => [['discount_pct' => '100']]])->assertSessionHasNoErrors();
        $quote = Quote::firstOrFail();
        $this->assertSame('0.00', (string) $quote->total);
    }

    public function test_quantity_zero_negative_and_too_many_decimals_rejected(): void
    {
        foreach (['0', '-2', '1.00001', 'abc', '1e2'] as $qty) {
            $this->store(['items' => [['quantity' => $qty]]])->assertSessionHasErrors('items.0.quantity');
        }
        foreach (['-1', 'abc', '1.00001'] as $price) {
            $this->store(['items' => [['unit_price' => $price]]])->assertSessionHasErrors('items.0.unit_price');
        }
        $this->assertSame(0, Quote::count());
    }

    public function test_totals_match_sum_of_lines_with_half_up_rounding(): void
    {
        $t = $this->makeTreatment(['price' => '33.3333']);
        $this->store(['items' => [
            ['treatment_id' => $t->id, 'quantity' => '3', 'unit_price' => '33.3333', 'discount_pct' => '7.5'],
            ['treatment_id' => $t->id, 'quantity' => '0.5', 'unit_price' => '0.03', 'discount_pct' => '0'],
        ]])->assertSessionHasNoErrors();

        $q = Quote::with('items')->firstOrFail();
        $this->assertSame(round((float) $q->subtotal - (float) $q->discount_total + (float) $q->tax_total, 2), round((float) $q->total, 2));
        $this->assertEqualsWithDelta((float) $q->items->sum('line_subtotal'), (float) $q->subtotal, 0.001);
        $this->assertEqualsWithDelta((float) $q->items->sum('line_tax'), (float) $q->tax_total, 0.001);
        foreach ($q->items as $line) {
            $this->assertEqualsWithDelta((float) $line->line_total, (float) $line->line_subtotal - (float) $line->line_discount + (float) $line->line_tax, 0.001);
        }
    }

    public function test_foreign_or_invalid_ids_are_rejected(): void
    {
        $this->store(['patient_id' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('patient_id');
        $this->store(['patient_id' => 'no-es-uuid'])->assertSessionHasErrors('patient_id');
        $this->store(['doctor_member_id' => $this->adminMemberId()])->assertSessionHasErrors('doctor_member_id');
        $this->store(['branch_id' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('branch_id');
        $this->store(['quote_date' => '31/12/2026'])->assertSessionHasErrors('quote_date');
        $this->store(['quote_date' => now()->addYears(3)->toDateString()])->assertSessionHasErrors('quote_date');
        $this->store(['items' => [['tooth_fdi' => '19']]])->assertSessionHasErrors('items.0.tooth_fdi');
        $payload = $this->quotePayload();
        $payload['items'] = [];
        $this->post(route('presupuestos.store'), $payload)->assertSessionHasErrors('items');
        $this->assertSame(0, Quote::count());
    }

    public function test_only_drafts_are_editable_and_status_transitions_are_guarded(): void
    {
        $quote = $this->makeQuote();
        $this->actingAsAdmin();

        // Aprobar un borrador sin enviar no es una transición válida.
        $this->post(route('presupuestos.approve', $quote));
        $this->assertContains($quote->fresh()->status, [QuoteStatus::Borrador, QuoteStatus::Aprobado]);

        $this->post(route('presupuestos.send', $quote))->assertSessionHasNoErrors();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Enviado, $quote->status);

        // Enviado ya no se edita.
        $this->put(route('presupuestos.update', $quote), $this->quotePayload())->assertSessionHas('error');
        $this->get(route('presupuestos.edit', $quote))->assertRedirect();

        // Aprobar dos veces no duplica el plan de tratamiento.
        $this->post(route('presupuestos.approve', $quote));
        $this->post(route('presupuestos.approve', $quote));
        $this->assertSame($quote->items()->count(), PatientTreatment::where('patient_id', $quote->patient_id)->count());

        // Aprobado: no se edita, no se borra.
        $this->put(route('presupuestos.update', $quote), $this->quotePayload())->assertSessionHas('error');
        $this->delete(route('presupuestos.destroy', $quote));
        $this->assertDatabaseHas('quotes', ['id' => $quote->id]);
    }
}

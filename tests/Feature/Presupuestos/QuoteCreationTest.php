<?php

namespace Tests\Feature\Presupuestos;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\Treatment;
use Tests\TestCase;

class QuoteCreationTest extends TestCase
{
    use BuildsQuotes;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('presupuestos.index'))->assertRedirect(route('login'));
        $this->post(route('presupuestos.store'), [])->assertRedirect(route('login'));
    }

    public function test_create_form_renders_with_catalogs(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment(['name' => 'Limpieza profunda']);

        $this->get(route('presupuestos.create'))
            ->assertOk()
            ->assertSee('Nuevo presupuesto')
            ->assertSee('Agregar concepto')
            ->assertSee($treatment->name)
            ->assertSee('Dra. Mariana Reyes');
    }

    public function test_quote_is_created_with_folio_history_and_exact_totals(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $brackets = $this->makeTreatment(['name' => 'Brackets metálicos', 'price' => '12000.00']);
        $cleaning = $this->makeTreatment(['name' => 'Limpieza dental', 'price' => '800.00']);

        $response = $this->post(route('presupuestos.store'), $this->quotePayload($patient, null, [
            'items' => [
                ['treatment_id' => $brackets->id, 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '12000', 'discount_pct' => '0'],
                ['treatment_id' => $cleaning->id, 'tooth_fdi' => '', 'quantity' => '2', 'unit_price' => '800', 'discount_pct' => '10'],
            ],
        ]));

        $quote = Quote::query()->firstOrFail();
        $response->assertRedirect(route('presupuestos.show', $quote));

        $this->assertSame('PRE-0001', $quote->folio);
        $this->assertSame(QuoteStatus::Borrador, $quote->status);
        // Mismo resultado que el prototipo: subtotal 13,440 (con descuento) + IVA 2,150.40 = 15,590.40
        $this->assertSame('13600.00', (string) $quote->subtotal);
        $this->assertSame('160.00', (string) $quote->discount_total);
        $this->assertSame('2150.40', (string) $quote->tax_total);
        $this->assertSame('15590.40', (string) $quote->total);
        $this->assertSame(2, $quote->items()->count());
        $this->assertSame(1, $quote->statusHistory()->count());
        $this->assertNull($quote->statusHistory->first()->from_status);
        $this->assertSame($quote->items->sum(fn ($i) => (float) $i->line_total), (float) $quote->total);
    }

    public function test_folios_are_consecutive(): void
    {
        $this->actingAsAdmin();

        $this->post(route('presupuestos.store'), $this->quotePayload());
        $this->post(route('presupuestos.store'), $this->quotePayload());

        $this->assertSame(['PRE-0001', 'PRE-0002'], Quote::query()->orderBy('folio')->pluck('folio')->all());
    }

    public function test_item_snapshots_do_not_change_when_catalog_changes(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment(['name' => 'Resina', 'price' => '950.00']);
        $this->post(route('presupuestos.store'), $this->quotePayload(null, $treatment));

        $treatment->update(['name' => 'Resina premium', 'price' => '2000.00']);
        $item = Quote::query()->firstOrFail()->items->first();

        $this->assertSame('Resina', $item->description_snapshot);
        $this->assertSame('85121800', $item->sat_product_key_snapshot);
        $this->assertSame('950.0000', (string) $item->unit_price);
        $this->assertSame('0.160000', (string) $item->tax_rate_snapshot);
    }

    public function test_rounding_is_half_up_per_line(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment(['price' => '0.3350']);

        // 3 x 0.335 = 1.005 -> 1.01 ; IVA 16% de 1.01 = 0.1616 -> 0.16
        $this->post(route('presupuestos.store'), $this->quotePayload(null, $treatment, [
            'items' => [['treatment_id' => $treatment->id, 'quantity' => '3', 'unit_price' => '0.335', 'discount_pct' => '0']],
        ]));

        $item = Quote::query()->firstOrFail()->items->first();
        $this->assertSame('1.01', (string) $item->line_subtotal);
        $this->assertSame('0.16', (string) $item->line_tax);
        $this->assertSame('1.17', (string) $item->line_total);
    }

    public function test_exempt_treatment_has_no_tax(): void
    {
        $this->actingAsAdmin();
        $exento = \App\Models\TaxProfile::query()->where('is_exempt', true)->firstOrFail();
        $treatment = $this->makeTreatment(['tax_profile_id' => $exento->id, 'price' => '1000.00']);

        $this->post(route('presupuestos.store'), $this->quotePayload(null, $treatment));

        $quote = Quote::query()->firstOrFail();
        $this->assertSame('0.00', (string) $quote->tax_total);
        $this->assertSame('1000.00', (string) $quote->total);
        $this->assertTrue($quote->items->first()->tax_is_exempt);
    }

    public function test_tooth_and_free_price_are_stored(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment(['price' => '800.00']);

        $this->post(route('presupuestos.store'), $this->quotePayload(null, $treatment, [
            'items' => [['treatment_id' => $treatment->id, 'tooth_fdi' => '46', 'quantity' => '1', 'unit_price' => '750.50', 'discount_pct' => '0']],
        ]));

        $item = Quote::query()->firstOrFail()->items->first();
        $this->assertSame(46, $item->tooth_fdi);
        $this->assertSame('750.5000', (string) $item->unit_price);
    }

    public function test_empty_rows_are_ignored_but_at_least_one_concept_is_required(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment();
        $payload = $this->quotePayload(null, $treatment);
        $payload['items'][] = ['treatment_id' => '', 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '', 'discount_pct' => '0'];

        $this->post(route('presupuestos.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Quote::query()->firstOrFail()->items()->count());

        $payload['items'] = [['treatment_id' => '', 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '', 'discount_pct' => '0']];
        $this->post(route('presupuestos.store'), $payload)->assertSessionHasErrors('items');
        $this->assertSame(1, Quote::query()->count());
    }

    public function test_patient_search_returns_active_patients_only(): void
    {
        $this->actingAsAdmin();
        $this->makePatient(['full_name' => 'Ana Lucía Robles']);
        $this->makePatient(['full_name' => 'Ana Inactiva', 'status' => 'inactive']);

        $this->getJson(route('presupuestos.patients', ['q' => 'Ana']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Ana Lucía Robles');
    }

    public function test_treatment_catalog_data_reaches_the_form_for_live_totals(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment(['price' => '950.00']);

        $this->get(route('presupuestos.create'))
            ->assertOk()
            ->assertSee('data-treatments', false)
            ->assertSee($treatment->id, false);
    }

    public function test_inactive_treatment_is_not_offered(): void
    {
        $this->actingAsAdmin();
        $inactive = Treatment::create([
            'name' => 'Tratamiento retirado', 'duration_minutes' => 30, 'sat_product_key' => '85121800',
            'sat_unit_key' => 'E48', 'price' => '100', 'currency' => 'MXN', 'status' => 'inactive',
        ]);

        $this->get(route('presupuestos.create'))->assertOk()->assertDontSee($inactive->name);
    }
}

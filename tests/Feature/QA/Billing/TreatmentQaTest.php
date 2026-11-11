<?php

namespace Tests\Feature\QA\Billing;

use App\Enums\RecordStatus;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use Tests\Feature\Presupuestos\BuildsQuotes;
use Tests\TestCase;

/** QA: validación del catálogo de tratamientos y coherencia con Agenda/Presupuestos. */
class TreatmentQaTest extends TestCase
{
    use BuildsQuotes;

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Resina compuesta QA',
            'category_name' => 'Restauradora',
            'duration_minutes' => '30',
            'sat_product_key' => '85121800',
            'sat_unit_key' => 'E48',
            'price' => '950',
            'tax_profile_id' => TaxProfile::query()->where('name', 'IVA 16%')->value('id'),
        ], $override);
    }

    public function test_duration_matches_what_the_agenda_can_schedule(): void
    {
        $this->actingAsAdmin();
        foreach (['1', '7', '14', '12', '481', '600', '1440'] as $minutes) {
            $this->post(route('tratamientos.store'), $this->payload(['duration_minutes' => $minutes]))
                ->assertSessionHasErrors('duration_minutes');
        }
        $this->assertSame(0, Treatment::count());

        foreach (['15', '45', '480'] as $minutes) {
            $this->post(route('tratamientos.store'), $this->payload(['name' => "Tratamiento $minutes", 'duration_minutes' => $minutes]))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(3, Treatment::count());
    }

    public function test_domain_validation_of_treatment_fields(): void
    {
        $this->actingAsAdmin();
        $bad = [
            ['name' => 'ab'], ['name' => ''], ['name' => str_repeat('n', 201)],
            ['sat_product_key' => '1234567'], ['sat_product_key' => 'ABCDEFGH'], ['sat_unit_key' => 'XYZ'],
            ['price' => '-1'], ['price' => '12.34567'], ['price' => 'gratis'], ['price' => '100000000'],
            ['tax_profile_id' => '00000000-0000-4000-8000-000000000000'],
            ['category_name' => str_repeat('c', 101)],
        ];
        foreach ($bad as $i => $override) {
            $this->post(route('tratamientos.store'), $this->payload($override));
            $this->assertTrue(session()->has('errors'), "Caso $i debio rechazarse: ".json_encode($override));
        }
        $this->assertSame(0, Treatment::count());
    }

    public function test_duplicate_names_are_rejected_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $this->post(route('tratamientos.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('tratamientos.store'), $this->payload(['name' => 'RESINA COMPUESTA qa']))->assertSessionHasErrors('name');
        $this->assertSame(1, Treatment::count());
    }

    public function test_inactive_tax_profile_cannot_be_assigned_and_exempt_requires_zero_rate(): void
    {
        $this->actingAsAdmin();
        $inactive = TaxProfile::create(['name' => 'Viejo', 'tax_code' => '002', 'rate' => '0.080000', 'is_exempt' => false, 'status' => RecordStatus::Inactive]);
        $this->post(route('tratamientos.store'), $this->payload(['tax_profile_id' => $inactive->id]))->assertSessionHasErrors('tax_profile_id');

        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Exento mal', 'tax_code' => '002', 'rate_pct' => '16', 'is_exempt' => '1'])
            ->assertSessionHasErrors('rate_pct', null, 'taxProfile');
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Tasa 101', 'tax_code' => '002', 'rate_pct' => '101'])
            ->assertSessionHasErrors('rate_pct', null, 'taxProfile');
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Tasa rara', 'tax_code' => '999', 'rate_pct' => '16'])
            ->assertSessionHasErrors('tax_code', null, 'taxProfile');
    }

    public function test_changing_catalog_price_or_tax_does_not_alter_existing_quotes(): void
    {
        $treatment = $this->makeTreatment(['price' => '1000']);
        $quote = $this->makeQuote(null, [[
            'treatment_id' => $treatment->id, 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '1000', 'discount_pct' => '0',
        ]]);
        $before = [(string) $quote->total, (string) $quote->items()->first()->tax_rate_snapshot];

        $treatment->update(['price' => '5000']);
        TaxProfile::query()->where('id', $treatment->tax_profile_id)->update(['rate' => '0.080000']);

        $quote->refresh();
        $this->assertSame($before, [(string) $quote->total, (string) $quote->items()->first()->tax_rate_snapshot]);
    }

    public function test_category_with_treatments_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $category = TreatmentCategory::create(['name' => 'Con tratamientos']);
        $this->makeTreatment(['category_id' => $category->id]);

        $this->delete(route('tratamientos.categorias.destroy', $category));
        $this->assertDatabaseHas('treatment_categories', ['id' => $category->id]);
    }

    public function test_used_treatment_is_deactivated_not_deleted(): void
    {
        $treatment = $this->makeTreatment();
        $this->makeQuote(null, [[
            'treatment_id' => $treatment->id, 'tooth_fdi' => '', 'quantity' => '1', 'unit_price' => '800', 'discount_pct' => '0',
        ]]);
        $this->actingAsAdmin();

        $this->delete(route('tratamientos.destroy', $treatment));

        $this->assertDatabaseHas('treatments', ['id' => $treatment->id, 'status' => 'inactive']);
    }
}

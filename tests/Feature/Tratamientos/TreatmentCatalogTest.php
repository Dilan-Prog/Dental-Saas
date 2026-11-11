<?php

namespace Tests\Feature\Tratamientos;

use App\Enums\RecordStatus;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use Illuminate\Support\Str;
use Tests\Feature\Presupuestos\BuildsQuotes;
use Tests\TestCase;

class TreatmentCatalogTest extends TestCase
{
    use BuildsQuotes;

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Limpieza dental',
            'category_name' => 'Preventiva',
            'duration_minutes' => '45',
            'sat_product_key' => '85121800',
            'sat_unit_key' => 'E48',
            'price' => '800',
            'tax_profile_id' => TaxProfile::query()->where('name', 'IVA 16%')->value('id'),
        ], $override);
    }

    public function test_guest_and_unauthorized_users_cannot_enter(): void
    {
        $this->get(route('tratamientos.index'))->assertRedirect(route('login'));

        $this->actingAsReceptionist();
        $this->get(route('tratamientos.index'))->assertForbidden();
        $this->post(route('tratamientos.store'), $this->payload())->assertForbidden();
        $this->assertSame(0, Treatment::query()->count());
    }

    public function test_index_shows_catalog_with_figma_columns(): void
    {
        $this->actingAsAdmin();
        $this->seed(\Database\Seeders\DemoTratamientosSeeder::class);

        $this->get(route('tratamientos.index'))->assertOk()
            ->assertSee('Catálogo de tratamientos')->assertSee('Nuevo tratamiento')
            ->assertSee('Consulta de valoración')->assertSee('$450.00')->assertSee('85121800')->assertSee('30 min')
            ->assertSee('Diagnóstico');
    }

    public function test_index_filters_by_text_category_and_status(): void
    {
        $this->actingAsAdmin();
        $this->seed(\Database\Seeders\DemoTratamientosSeeder::class);
        Treatment::query()->where('name', 'Blanqueamiento')->update(['status' => 'inactive']);
        $orto = TreatmentCategory::query()->where('name', 'Ortodoncia')->value('id');

        $this->get(route('tratamientos.index', ['q' => 'limpieza']))->assertSee('Limpieza dental')->assertDontSee('Implante dental');
        $this->get(route('tratamientos.index', ['categoria' => $orto]))->assertSee('Brackets metálicos')->assertDontSee('Limpieza dental');
        $this->get(route('tratamientos.index'))->assertDontSee('Blanqueamiento');
        $this->get(route('tratamientos.index', ['estado' => 'inactivos']))->assertSee('Blanqueamiento')->assertDontSee('Limpieza dental');
        $this->get(route('tratamientos.index', ['estado' => 'todos']))->assertSee('Blanqueamiento')->assertSee('Limpieza dental');
        $this->get(route('tratamientos.index', ['categoria' => 'x', 'estado' => 'z', 'tab' => 'q']))->assertOk();
    }

    public function test_treatment_is_created_with_category_created_on_the_fly(): void
    {
        $this->actingAsAdmin();

        $this->post(route('tratamientos.store'), $this->payload())->assertRedirect(route('tratamientos.index'))->assertSessionHas('success');

        $t = Treatment::query()->firstOrFail();
        $this->assertSame('Limpieza dental', $t->name);
        $this->assertSame(45, $t->duration_minutes);
        $this->assertSame('800.0000', (string) $t->price);
        $this->assertSame('MXN', $t->currency);
        $this->assertSame(RecordStatus::Active, $t->status);
        $this->assertSame('Preventiva', $t->category->name);
    }

    public function test_existing_category_is_reused_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $this->post(route('tratamientos.store'), $this->payload());
        $this->post(route('tratamientos.store'), $this->payload(['name' => 'Fluorización', 'category_name' => 'preventiva']));

        $this->assertSame(1, TreatmentCategory::query()->count());
        $this->assertSame(2, Treatment::query()->count());
    }

    public function test_duration_accepts_min_suffix_and_price_dollar_sign(): void
    {
        $this->actingAsAdmin();
        $this->post(route('tratamientos.store'), $this->payload(['duration_minutes' => '30 min', 'price' => '$1,200']))
            ->assertSessionHasErrors('price'); // la coma no se admite

        $this->post(route('tratamientos.store'), $this->payload(['duration_minutes' => '30 min', 'price' => '$1200']))
            ->assertSessionHasNoErrors();
        $this->assertSame(30, Treatment::query()->firstOrFail()->duration_minutes);
    }

    /** @dataProvider invalidInputs */
    public function test_invalid_treatment_data_is_rejected(array $override, string $errorKey): void
    {
        $this->actingAsAdmin();

        $this->post(route('tratamientos.store'), $this->payload($override))->assertSessionHasErrors($errorKey);
        $this->assertSame(0, Treatment::query()->count());
    }

    public static function invalidInputs(): array
    {
        return [
            'nombre vacío' => [['name' => ''], 'name'],
            'nombre corto' => [['name' => 'ab'], 'name'],
            'nombre largo' => [['name' => str_repeat('a', 201)], 'name'],
            'duración cero' => [['duration_minutes' => '0'], 'duration_minutes'],
            'duración negativa' => [['duration_minutes' => '-5'], 'duration_minutes'],
            'duración texto' => [['duration_minutes' => 'rápido'], 'duration_minutes'],
            'duración decimal' => [['duration_minutes' => '30.5'], 'duration_minutes'],
            'duración > 24 h' => [['duration_minutes' => '1441'], 'duration_minutes'],
            'precio vacío' => [['price' => ''], 'price'],
            'precio negativo' => [['price' => '-1'], 'price'],
            'precio texto' => [['price' => 'barato'], 'price'],
            'precio 5 decimales' => [['price' => '1.12345'], 'price'],
            'precio enorme' => [['price' => '100000000'], 'price'],
            'clave SAT vacía' => [['sat_product_key' => ''], 'sat_product_key'],
            'clave SAT corta' => [['sat_product_key' => '8512'], 'sat_product_key'],
            'clave SAT con letras' => [['sat_product_key' => '8512180A'], 'sat_product_key'],
            'unidad SAT inexistente' => [['sat_unit_key' => 'ZZZ'], 'sat_unit_key'],
            'perfil de impuesto inexistente' => [['tax_profile_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'], 'tax_profile_id'],
            'perfil de impuesto no uuid' => [['tax_profile_id' => 'iva'], 'tax_profile_id'],
            'categoría muy larga' => [['category_name' => str_repeat('c', 101)], 'category_name'],
        ];
    }

    public function test_name_must_be_unique_but_can_be_kept_on_update(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeTreatment(['name' => 'Resina']);
        $b = $this->makeTreatment(['name' => 'Corona']);

        $this->post(route('tratamientos.store'), $this->payload(['name' => 'resina']))->assertSessionHasErrors('name');
        $this->put(route('tratamientos.update', $b), $this->payload(['name' => 'Resina']))->assertSessionHasErrors('name');
        $this->put(route('tratamientos.update', $a), $this->payload(['name' => 'Resina', 'price' => '999']))->assertSessionHasNoErrors();

        $this->assertSame('999.0000', (string) $a->fresh()->price);
    }

    public function test_treatment_can_be_updated_and_toggled(): void
    {
        $this->actingAsAdmin();
        $t = $this->makeTreatment(['name' => 'Resina']);

        $this->put(route('tratamientos.update', $t), $this->payload(['name' => 'Resina compuesta', 'category_name' => 'Restaurativa', 'duration_minutes' => '60', 'price' => '1100']))
            ->assertRedirect()->assertSessionHas('success');
        $t->refresh();
        $this->assertSame('Resina compuesta', $t->name);
        $this->assertSame(60, $t->duration_minutes);
        $this->assertSame('Restaurativa', $t->category->name);

        $this->patch(route('tratamientos.toggle', $t))->assertSessionHas('success');
        $this->assertSame(RecordStatus::Inactive, $t->fresh()->status);
        $this->patch(route('tratamientos.toggle', $t))->assertSessionHas('success');
        $this->assertSame(RecordStatus::Active, $t->fresh()->status);
    }

    public function test_unused_treatment_is_deleted_and_used_one_is_only_deactivated(): void
    {
        $this->actingAsAdmin();
        $unused = $this->makeTreatment();
        $used = $this->makeTreatment();
        $this->makeQuote(null, [['treatment_id' => $used->id, 'quantity' => '1', 'unit_price' => '800', 'discount_pct' => '0']]);

        $this->delete(route('tratamientos.destroy', $unused))->assertRedirect()->assertSessionHas('success');
        $this->assertNull(Treatment::query()->find($unused->id));

        $this->delete(route('tratamientos.destroy', $used))->assertRedirect()->assertSessionHas('warning');
        $this->assertSame(RecordStatus::Inactive, $used->fresh()->status);
    }

    public function test_unknown_treatment_returns_404(): void
    {
        $this->actingAsAdmin();
        $this->put(route('tratamientos.update', (string) Str::uuid()), $this->payload())->assertNotFound();
        $this->delete(route('tratamientos.destroy', (string) Str::uuid()))->assertNotFound();
    }

    // ------------------------------------------------------------ categorías

    public function test_categories_crud_and_validation(): void
    {
        $this->actingAsAdmin();

        $this->post(route('tratamientos.categorias.store'), ['name' => ''])->assertSessionHasErrors('name', null, 'category');
        $this->post(route('tratamientos.categorias.store'), ['name' => 'x'])->assertSessionHasErrors('name', null, 'category');
        $this->post(route('tratamientos.categorias.store'), ['name' => 'Cirugía'])->assertSessionHasNoErrors();
        $this->post(route('tratamientos.categorias.store'), ['name' => 'cirugía'])->assertSessionHasErrors('name', null, 'category');
        $this->post(route('tratamientos.categorias.store'), ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name', null, 'category');

        $cat = TreatmentCategory::query()->where('name', 'Cirugía')->firstOrFail();
        $this->put(route('tratamientos.categorias.update', $cat), ['name' => 'Cirugía oral'])->assertSessionHasNoErrors();
        $this->assertSame('Cirugía oral', $cat->fresh()->name);

        $this->delete(route('tratamientos.categorias.destroy', $cat))->assertSessionHas('success');
        $this->assertNull(TreatmentCategory::query()->find($cat->id));
    }

    public function test_category_with_treatments_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $t = $this->makeTreatment();

        $this->delete(route('tratamientos.categorias.destroy', $t->category))->assertSessionHas('error');
        $this->assertNotNull(TreatmentCategory::query()->find($t->category_id));
    }

    // ------------------------------------------------------------ impuestos

    public function test_tax_profile_stores_rate_as_fraction_and_validates(): void
    {
        $this->actingAsAdmin();

        $this->post(route('tratamientos.impuestos.store'), ['name' => 'IVA 8%', 'tax_code' => '002', 'rate_pct' => '8'])
            ->assertSessionHasNoErrors();
        $this->assertSame('0.080000', (string) TaxProfile::query()->where('name', 'IVA 8%')->firstOrFail()->rate);

        $bag = 'taxProfile';
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'IVA 8%', 'tax_code' => '002', 'rate_pct' => '8'])->assertSessionHasErrors('name', null, $bag);
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Malo', 'tax_code' => '999', 'rate_pct' => '8'])->assertSessionHasErrors('tax_code', null, $bag);
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Malo', 'tax_code' => '002', 'rate_pct' => '101'])->assertSessionHasErrors('rate_pct', null, $bag);
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Malo', 'tax_code' => '002', 'rate_pct' => '-1'])->assertSessionHasErrors('rate_pct', null, $bag);
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Malo', 'tax_code' => '002', 'rate_pct' => 'x'])->assertSessionHasErrors('rate_pct', null, $bag);
        $this->post(route('tratamientos.impuestos.store'), ['name' => 'Malo', 'tax_code' => '002', 'rate_pct' => '16', 'is_exempt' => '1'])->assertSessionHasErrors('rate_pct', null, $bag);
        $this->assertNull(TaxProfile::query()->where('name', 'Malo')->first());
    }

    public function test_tax_profile_update_and_toggle_do_not_alter_existing_quotes(): void
    {
        $this->actingAsAdmin();
        $quote = $this->makeQuote();
        $profile = TaxProfile::query()->where('name', 'IVA 16%')->firstOrFail();

        $this->put(route('tratamientos.impuestos.update', $profile), ['name' => 'IVA 16%', 'tax_code' => '002', 'rate_pct' => '10'])->assertSessionHasNoErrors();
        $this->assertSame('0.100000', (string) $profile->fresh()->rate);
        // El presupuesto ya creado conserva la tasa de su momento (snapshot).
        $this->assertSame('0.160000', (string) $quote->items()->first()->tax_rate_snapshot);

        $this->patch(route('tratamientos.impuestos.toggle', $profile))->assertSessionHas('success');
        $this->assertSame(RecordStatus::Inactive, $profile->fresh()->status);
    }

    public function test_inactive_tax_profile_is_not_accepted_for_new_treatments(): void
    {
        $this->actingAsAdmin();
        $profile = TaxProfile::query()->where('name', 'IVA 16%')->firstOrFail();
        $profile->update(['status' => 'inactive']);

        $this->post(route('tratamientos.store'), $this->payload(['tax_profile_id' => $profile->id]))->assertSessionHasErrors('tax_profile_id');
    }

    public function test_tabs_render(): void
    {
        $this->actingAsAdmin();
        $this->makeTreatment();

        $this->get(route('tratamientos.index', ['tab' => 'categorias']))->assertOk()->assertSee('Categorías de tratamientos');
        $this->get(route('tratamientos.index', ['tab' => 'impuestos']))->assertOk()->assertSee('Perfiles de impuestos')->assertSee('IVA 16%');
    }
}

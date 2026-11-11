<?php

namespace Tests\Feature\Recetas;

use App\Models\Medication;
use App\Models\Organization;
use Illuminate\Support\Str;

class MedicationCatalogTest extends RecetasTestCase
{
    private function validMed(array $over = []): array
    {
        return $over + [
            'name' => 'Metronidazol 500 mg',
            'active_ingredient' => 'Metronidazol',
            'strength' => '500 mg',
            'presentation' => 'Tabletas',
        ];
    }

    public function test_catalog_lists_global_and_own_medications_with_filters(): void
    {
        $this->makeGlobalMedication(['name' => 'Global Uno 10 mg']);
        $this->makeMedication(['name' => 'Propio Dos 20 mg']);
        $this->actingAsDoctor();

        $this->get(route('recetas.medicamentos.index'))->assertOk()
            ->assertSee('Global Uno 10 mg')->assertSee('Propio Dos 20 mg')->assertSee('Solo lectura');
        $this->get(route('recetas.medicamentos.index', ['origen' => 'global']))
            ->assertSee('Global Uno 10 mg')->assertDontSee('Propio Dos 20 mg');
        $this->get(route('recetas.medicamentos.index', ['origen' => 'clinica']))
            ->assertSee('Propio Dos 20 mg')->assertDontSee('Global Uno 10 mg');
        $this->get(route('recetas.medicamentos.index', ['q' => 'Dos']))
            ->assertSee('Propio Dos 20 mg')->assertDontSee('Global Uno 10 mg');
    }

    public function test_doctor_creates_an_own_medication(): void
    {
        $this->actingAsDoctor();

        $this->post(route('recetas.medicamentos.store'), $this->validMed())
            ->assertRedirect(route('recetas.medicamentos.index'))->assertSessionHas('success');

        $med = Medication::query()->where('name', 'Metronidazol 500 mg')->firstOrFail();
        $this->assertSame(Organization::currentId(), $med->organization_id);
        $this->assertSame('active', $med->status->value);
    }

    public function test_cannot_create_for_another_organization_via_payload(): void
    {
        $this->actingAsDoctor();
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['organization_id' => (string) Str::uuid()]));

        $this->assertSame(Organization::currentId(), Medication::query()->firstWhere('name', 'Metronidazol 500 mg')->organization_id);
    }

    public function test_validation_rules(): void
    {
        $this->actingAsDoctor();
        $this->post(route('recetas.medicamentos.store'), [])->assertSessionHasErrors('name');
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['name' => str_repeat('a', 201)]))->assertSessionHasErrors('name');
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['active_ingredient' => str_repeat('a', 201)]))->assertSessionHasErrors('active_ingredient');
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['strength' => str_repeat('a', 81)]))->assertSessionHasErrors('strength');
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['presentation' => str_repeat('a', 121)]))->assertSessionHasErrors('presentation');
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['status' => 'otro']))->assertSessionHasErrors('status');
        $this->assertSame(0, Medication::query()->count());
    }

    public function test_duplicates_are_rejected_including_global_catalog(): void
    {
        $this->makeGlobalMedication(['name' => 'Amoxicilina 500 mg', 'strength' => '500 mg', 'presentation' => 'Cápsulas']);
        $this->actingAsDoctor();

        $this->post(route('recetas.medicamentos.store'), $this->validMed(['name' => 'Amoxicilina 500 mg', 'strength' => '500 mg', 'presentation' => 'Cápsulas']))
            ->assertSessionHasErrors('name');
        // Misma denominación con otra presentación sí se permite.
        $this->post(route('recetas.medicamentos.store'), $this->validMed(['name' => 'Amoxicilina 500 mg', 'strength' => '500 mg', 'presentation' => 'Suspensión']))
            ->assertSessionHasNoErrors();
        $this->post(route('recetas.medicamentos.store'), $this->validMed())->assertSessionHasNoErrors();
        $this->post(route('recetas.medicamentos.store'), $this->validMed())->assertSessionHasErrors('name');
    }

    public function test_update_own_medication_and_keep_uniqueness_against_itself(): void
    {
        $med = $this->makeMedication();
        $this->actingAsDoctor();

        $this->put(route('recetas.medicamentos.update', $med), $this->validMed(['name' => 'Paracetamol 500 mg', 'strength' => '500 mg', 'presentation' => 'Tabletas', 'status' => 'active']))
            ->assertSessionHasNoErrors();
        $this->put(route('recetas.medicamentos.update', $med), $this->validMed(['name' => 'Paracetamol 650 mg', 'status' => 'inactive']))
            ->assertRedirect(route('recetas.medicamentos.index'));

        $med->refresh();
        $this->assertSame('Paracetamol 650 mg', $med->name);
        $this->assertSame('inactive', $med->status->value);
        $this->assertSame(Organization::currentId(), $med->organization_id);

        $this->put(route('recetas.medicamentos.update', $med), $this->validMed(['name' => 'X']))->assertSessionHasErrors('status');
    }

    public function test_global_medications_are_read_only(): void
    {
        $global = $this->makeGlobalMedication();
        $this->actingAsDoctor();

        $this->put(route('recetas.medicamentos.update', $global), $this->validMed(['name' => 'Cambio', 'status' => 'active']))
            ->assertSessionHas('error');
        $this->patch(route('recetas.medicamentos.toggle', $global))->assertSessionHas('error');

        $global = Medication::withoutGlobalScopes()->find($global->id);
        $this->assertSame('Amoxicilina 500 mg', $global->name);
        $this->assertSame('active', $global->status->value);
    }

    public function test_toggle_deactivates_and_hides_from_prescription_search(): void
    {
        $med = $this->makeMedication(['name' => 'Buscable 100 mg']);
        $this->actingAsDoctor();

        $this->getJson(route('recetas.medicamentos.buscar', ['q' => 'Buscable']))->assertJsonCount(1)->assertJsonPath('0.name', 'Buscable 100 mg');

        $this->patch(route('recetas.medicamentos.toggle', $med))->assertSessionHas('success');
        $this->assertSame('inactive', $med->fresh()->status->value);
        $this->getJson(route('recetas.medicamentos.buscar', ['q' => 'Buscable']))->assertJsonCount(0);

        $this->patch(route('recetas.medicamentos.toggle', $med));
        $this->assertSame('active', $med->fresh()->status->value);
    }

    public function test_search_returns_global_and_own_active_medications(): void
    {
        $this->makeGlobalMedication(['name' => 'Global Ibuprofeno 400 mg', 'strength' => '400 mg', 'presentation' => 'Tabletas']);
        $this->makeMedication(['name' => 'Propio Ibuprofeno 600 mg', 'strength' => '600 mg']);
        $this->actingAsDoctor();

        $names = collect($this->getJson(route('recetas.medicamentos.buscar', ['q' => 'Ibuprofeno']))->assertOk()->json())->pluck('name')->all();
        sort($names);
        $this->assertSame(['Global Ibuprofeno 400 mg', 'Propio Ibuprofeno 600 mg'], $names);
    }
}

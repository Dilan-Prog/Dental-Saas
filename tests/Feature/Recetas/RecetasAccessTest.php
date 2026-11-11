<?php

namespace Tests\Feature\Recetas;

class RecetasAccessTest extends RecetasTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('recetas.index'))->assertRedirect(route('login'));
        $this->get(route('recetas.create'))->assertRedirect(route('login'));
        $this->get(route('recetas.medicamentos.index'))->assertRedirect(route('login'));
    }

    public function test_receptionist_has_no_access_to_prescriptions(): void
    {
        $rx = $this->makeDraft();
        $this->actingAsReceptionist();

        $this->get(route('recetas.index'))->assertForbidden();
        $this->get(route('recetas.show', $rx))->assertForbidden();
        $this->get(route('recetas.create'))->assertForbidden();
        $this->post(route('recetas.store'), [])->assertForbidden();
        $this->get(route('recetas.medicamentos.index'))->assertForbidden();
        $this->getJson(route('recetas.pacientes.buscar'))->assertForbidden();
    }

    public function test_doctor_sees_index_with_kpis_and_selected_document(): void
    {
        $patient = $this->makePatient(['full_name' => 'Patricio Guerra Méndez']);
        $signed = $this->makeSigned($patient);
        $this->makeDraft($this->makePatient(['full_name' => 'Renata Aguilar Vega'], 'Penicilina'));

        $this->actingAsDoctor();
        $response = $this->get(route('recetas.index'));

        $response->assertOk()
            ->assertSee('Recetas del mes')
            ->assertSee('Firmadas')
            ->assertSee('Borradores')
            ->assertSee('Patricio Guerra Méndez')
            ->assertSee('Renata Aguilar Vega')
            ->assertSee($signed->folio)
            ->assertSee('Crear receta');

        $this->assertSame(2, $response->viewData('kpis')['month']);
        $this->assertSame(1, $response->viewData('kpis')['signed']);
        $this->assertSame(1, $response->viewData('kpis')['drafts']);
    }

    public function test_show_renders_document_of_selected_prescription(): void
    {
        $rx = $this->makeSigned();
        $this->actingAsDoctor();

        $this->get(route('recetas.show', $rx))
            ->assertOk()
            ->assertSee('Rx')
            ->assertSee($rx->folio)
            ->assertSee('Céd. Prof. 1234567')
            ->assertSee('Ibuprofeno 400 mg')
            ->assertSee('Repetir receta');
    }

    public function test_index_filters_by_status_and_search(): void
    {
        $this->makeSigned($this->makePatient(['full_name' => 'Ana Torres Ruiz']));
        $this->makeDraft($this->makePatient(['full_name' => 'Luis Mora Díaz']));
        $this->actingAsDoctor();

        $this->get(route('recetas.index', ['estado' => 'firmada']))
            ->assertSee('Ana Torres Ruiz')->assertDontSee('Luis Mora Díaz');
        $this->get(route('recetas.index', ['estado' => 'borrador']))
            ->assertSee('Luis Mora Díaz')->assertDontSee('Ana Torres Ruiz');
        $this->get(route('recetas.index', ['q' => 'Mora']))
            ->assertSee('Luis Mora Díaz')->assertDontSee('Ana Torres Ruiz');
        $this->get(route('recetas.index', ['q' => 'zzz-no-existe']))
            ->assertOk()->assertSee('Sin resultados');
        // Valor de estado inválido se ignora (no rompe).
        $this->get(route('recetas.index', ['estado' => 'hack']))->assertOk();
    }

    public function test_empty_state_when_there_are_no_prescriptions(): void
    {
        $this->actingAsDoctor();
        $this->get(route('recetas.index'))->assertOk()->assertSee('Aún no hay recetas');
    }

    public function test_create_screen_opens_the_modal_with_doctor_and_patient_preset(): void
    {
        $patient = $this->makePatient(['full_name' => 'Valeria Domínguez Soto'], 'Latex');
        $this->actingAsDoctor();

        $this->get(route('recetas.create', ['patient' => $patient->id]))
            ->assertOk()
            ->assertSee('Crear receta')
            ->assertSee('Firmar y emitir')
            ->assertSee('Guardar borrador')
            ->assertSee('Vista de impresión')
            ->assertSee('Valeria Domínguez Soto · '.$patient->folio, false)
            ->assertSee('Latex')
            ->assertSee('Dra. Mariana Reyes');
    }

    public function test_edit_screen_only_for_drafts(): void
    {
        $draft = $this->makeDraft();
        $signed = $this->makeSigned($this->makePatient(['full_name' => 'Otra Paciente Gómez']));
        $this->actingAsDoctor();

        $this->get(route('recetas.edit', $draft))->assertOk()->assertSee('Editar borrador '.$draft->folio);
        $this->get(route('recetas.edit', $signed))->assertRedirect(route('recetas.show', $signed))
            ->assertSessionHas('error');
    }

    public function test_unknown_prescription_returns_404(): void
    {
        $this->actingAsDoctor();
        $this->get(route('recetas.show', '00000000-0000-4000-8000-000000000000'))->assertNotFound();
    }
}

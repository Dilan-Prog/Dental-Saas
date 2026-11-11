<?php

namespace Tests\Feature\Expediente;

class ExpedienteAccessTest extends ExpedienteTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $patient = $this->makePatient();

        $this->get(route('expediente.show', $patient->id))->assertRedirect(route('login'));
        $this->get(route('documentos.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_open_every_tab(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        foreach (['resumen', 'historial', 'notas', 'archivos', 'odontograma', 'tratamientos', 'consentimientos'] as $tab) {
            $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee($patient->full_name);
        }
    }

    public function test_unknown_tab_falls_back_to_summary(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'inexistente']))->assertOk();
    }

    public function test_receptionist_without_expediente_permission_gets_403(): void
    {
        $patient = $this->makePatient();
        $this->actingAsReceptionist();

        $this->get(route('expediente.show', $patient->id))->assertForbidden();
    }

    public function test_doctor_can_open_expediente(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->get(route('expediente.show', $patient->id))->assertOk();
    }

    public function test_missing_patient_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->get(route('expediente.show', '00000000-0000-4000-8000-000000000000'))->assertNotFound();
    }

    public function test_allergies_are_highlighted_and_history_can_be_updated(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->put(route('expediente.historial.update', $patient->id), [
            'allergies' => 'Penicilina',
            'medical_history' => 'Hipertensión controlada',
        ])->assertRedirect();

        $this->get(route('expediente.show', $patient->id))->assertSee('Penicilina');
        $this->assertDatabaseHas('medical_records', ['patient_id' => $patient->id, 'allergies' => 'Penicilina']);
    }

    public function test_history_validation_limits_length(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->put(route('expediente.historial.update', $patient->id), ['allergies' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('allergies');
    }

    public function test_patient_basic_data_update_validates_and_saves(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->put(route('expediente.datos.update', $patient->id), [
            'full_name' => 'A', 'birth_date' => '2999-01-01', 'phone' => '123', 'email' => 'no-es-correo',
        ])->assertSessionHasErrors(['full_name', 'birth_date', 'phone', 'email']);

        $this->put(route('expediente.datos.update', $patient->id), [
            'full_name' => 'Valeria Domínguez Soto', 'birth_date' => '1992-03-04', 'phone' => '5518472093', 'email' => 'VALERIA@correo.mx',
        ])->assertRedirect();

        $patient->refresh();
        $this->assertSame('Valeria Domínguez Soto', $patient->full_name);
        $this->assertSame('55 1847 2093', $patient->phone);
        $this->assertSame('valeria@correo.mx', $patient->email);
    }
}

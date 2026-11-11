<?php

namespace Tests\Feature\Expediente;

use App\Models\ClinicalFile;
use App\Models\ConsentAcceptance;
use App\Models\PatientTreatment;
use Illuminate\Http\UploadedFile;

class TreatmentAndConsentTest extends ExpedienteTestCase
{
    // ------------------------------------------------------------ tratamientos

    public function test_treatment_is_added_to_the_plan_as_planned(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $t = $this->makeTreatment();

        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id, 'tooth_fdi' => 26])
            ->assertRedirect()->assertSessionHas('success');

        $item = PatientTreatment::query()->firstOrFail();
        $this->assertSame('planeado', $item->status->value);
        $this->assertNull($item->started_at);
        $this->assertSame(26, $item->tooth_fdi);
    }

    public function test_treatment_validation(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $t = $this->makeTreatment();

        $this->post(route('expediente.tratamientos.store', $patient->id), [])->assertSessionHasErrors('treatment_id');
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id, 'tooth_fdi' => 77])->assertSessionHasErrors('tooth_fdi');
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id, 'status' => 'volando'])->assertSessionHasErrors('status');
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id, 'status' => 'planeado', 'completed_at' => '2020-01-01'])
            ->assertSessionHasErrors('completed_at');
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id, 'status' => 'realizado', 'started_at' => '2024-05-02', 'completed_at' => '2024-05-01'])
            ->assertSessionHasErrors('completed_at');

        $this->assertSame(0, PatientTreatment::query()->count());
    }

    public function test_inactive_treatment_cannot_be_added(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $t = $this->makeTreatment();
        $t->update(['status' => 'inactive']);

        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $t->id])->assertSessionHasErrors('treatment_id');
    }

    public function test_status_flow_sets_dates_and_blocks_invalid_transitions(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $this->makeTreatment()->id]);
        $item = PatientTreatment::query()->firstOrFail();
        $url = route('expediente.tratamientos.status', [$patient->id, $item->id]);

        $this->patch($url, ['status' => 'en_progreso'])->assertSessionHas('success');
        $this->assertNotNull($item->fresh()->started_at);

        $this->patch($url, ['status' => 'planeado'])->assertSessionHas('error'); // no se puede retroceder desde en progreso

        $this->patch($url, ['status' => 'realizado'])->assertSessionHas('success');
        $item->refresh();
        $this->assertSame('realizado', $item->status->value);
        $this->assertNotNull($item->completed_at);

        $this->patch($url, ['status' => 'cancelado'])->assertSessionHas('error'); // realizado es terminal
        $this->patch($url, ['status' => 'xx'])->assertSessionHasErrors('status');
    }

    public function test_only_planned_manual_treatments_can_be_deleted(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $this->makeTreatment()->id]);
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $this->makeTreatment('Otro')->id, 'status' => 'en_progreso']);
        [$planned, $running] = [
            PatientTreatment::query()->where('status', 'planeado')->firstOrFail(),
            PatientTreatment::query()->where('status', 'en_progreso')->firstOrFail(),
        ];

        $this->delete(route('expediente.tratamientos.destroy', [$patient->id, $running->id]))->assertSessionHas('error');
        $this->assertDatabaseHas('patient_treatments', ['id' => $running->id]);

        $this->delete(route('expediente.tratamientos.destroy', [$patient->id, $planned->id]))->assertSessionHas('success');
        $this->assertDatabaseMissing('patient_treatments', ['id' => $planned->id]);
    }

    public function test_treatment_of_another_patient_is_not_reachable(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.tratamientos.store', $a->id), ['treatment_id' => $this->makeTreatment()->id]);
        $item = PatientTreatment::query()->firstOrFail();

        $this->patch(route('expediente.tratamientos.status', [$b->id, $item->id]), ['status' => 'en_progreso'])->assertNotFound();
    }

    public function test_treatments_tab_renders_with_plan(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.tratamientos.store', $patient->id), ['treatment_id' => $this->makeTreatment('Endodoncia unirradicular')->id]);

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'tratamientos']))
            ->assertOk()->assertSee('Endodoncia unirradicular')->assertSee('Planeado');
    }

    // ------------------------------------------------------------ consentimientos

    public function test_consent_acceptance_is_registered_with_hash(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => $patient->full_name, 'method' => 'verbal_registrada',
        ])->assertRedirect()->assertSessionHas('success');

        $c = ConsentAcceptance::query()->firstOrFail();
        $this->assertSame(64, strlen($c->content_hash));
        $this->assertNotNull($c->accepted_at);
        $this->assertNull($c->clinical_file_id);
    }

    public function test_scanned_signature_requires_a_consent_document(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => $patient->full_name, 'method' => 'firma_autografa_escaneada',
        ])->assertSessionHasErrors('clinical_file_id');

        $this->post(route('expediente.archivos.store', $patient->id), [
            'file' => UploadedFile::fake()->create('consent.pdf', 50, 'application/pdf'), 'file_type' => 'consentimiento',
        ]);
        $file = ClinicalFile::query()->firstOrFail();

        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => $patient->full_name, 'method' => 'firma_autografa_escaneada', 'clinical_file_id' => $file->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($file->id, ConsentAcceptance::query()->firstOrFail()->clinical_file_id);
    }

    public function test_document_of_wrong_type_cannot_back_a_scanned_signature(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.archivos.store', $patient->id), [
            'file' => UploadedFile::fake()->image('rx.png'), 'file_type' => 'radiografia',
        ]);
        $file = ClinicalFile::query()->firstOrFail();

        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => $patient->full_name, 'method' => 'firma_autografa_escaneada', 'clinical_file_id' => $file->id,
        ])->assertSessionHasErrors('clinical_file_id');
    }

    public function test_minor_patients_need_the_guardian_to_accept(): void
    {
        $minor = $this->makePatient(['birth_date' => now()->subYears(9)->format('Y-m-d'), 'full_name' => 'Mateo Niño Pérez']);
        $this->actingAsDoctor();

        $this->post(route('expediente.consentimientos.store', $minor->id), [
            'accepted_by_name' => 'Mateo Niño Pérez', 'method' => 'verbal_registrada',
        ])->assertSessionHasErrors('accepted_by_name');

        // Sin tutor registrado no se puede aceptar a nombre de un menor.
        $this->post(route('expediente.consentimientos.store', $minor->id), [
            'accepted_by_name' => 'Laura Pérez', 'method' => 'verbal_registrada',
        ])->assertSessionHasErrors('accepted_by_name');

        \App\Models\PatientGuardian::create(['patient_id' => $minor->id, 'full_name' => 'Laura Pérez', 'relationship' => 'Madre', 'phone' => '5511112222', 'is_primary' => true]);
        $this->post(route('expediente.consentimientos.store', $minor->id), [
            'accepted_by_name' => 'Laura Pérez', 'method' => 'verbal_registrada',
        ])->assertSessionHasNoErrors();
    }

    public function test_consent_validation(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.consentimientos.store', $patient->id), [])->assertSessionHasErrors(['accepted_by_name', 'method']);
        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => 'Ana', 'method' => 'telepatia',
        ])->assertSessionHasErrors('method');
        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => 'Ana López', 'method' => 'verbal_registrada', 'accepted_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('accepted_at');
        $this->post(route('expediente.consentimientos.store', $patient->id), [
            'accepted_by_name' => 'Ana López', 'method' => 'verbal_registrada', 'witness_member_id' => '00000000-0000-4000-8000-000000000000',
        ])->assertSessionHasErrors('witness_member_id');

        $this->assertSame(0, ConsentAcceptance::query()->count());
    }

    public function test_consents_tab_lists_acceptances(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.consentimientos.store', $patient->id), ['accepted_by_name' => 'Roberto Mendoza', 'method' => 'firma_digital']);

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'consentimientos']))
            ->assertOk()->assertSee('Roberto Mendoza')->assertSee('Firma digital');
    }
}

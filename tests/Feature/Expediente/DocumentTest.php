<?php

namespace Tests\Feature\Expediente;

use App\Models\ClinicalFile;
use App\Models\ConsentAcceptance;
use App\Services\Expediente\DocumentService;
use Illuminate\Support\Facades\Storage;

class DocumentTest extends ExpedienteTestCase
{
    private function historia(array $over = []): array
    {
        return $over + [
            'full_name' => 'Valeria Domínguez Soto', 'birth_date' => '1992-03-04', 'sex' => 'female', 'phone' => '55 1847 2093',
            'chief_complaint' => 'Revisión', 'bad_experience' => 'no', 'hygiene' => 'cepillo', 'brushing_times' => 3,
            'conditions' => ['diabetes'], 'blood_pressure' => '120/80', 'pulse' => 75, 'respiration' => 16, 'temperature' => 36.5,
        ];
    }

    private function autorizacion(array $over = []): array
    {
        return $over + [
            'diagnoses' => [['diagnosis' => 'Caries en 26', 'date' => now()->subDay()->format('Y-m-d')]],
            'activities' => [['date' => now()->format('Y-m-d'), 'activity' => 'Resina en 26', 'doctor' => 'Dra. Reyes']],
        ];
    }

    public function test_index_lists_both_templates(): void
    {
        $this->actingAsAdmin();

        $this->get(route('documentos.index'))->assertOk()
            ->assertSee('Generación de Documentos')->assertSee('Historia Clínica Dental')->assertSee('Autorización de Tratamiento');
    }

    public function test_create_form_loads_with_and_without_patient(): void
    {
        $patient = $this->makePatient(['full_name' => 'Renata Aguilar Vega']);
        $this->actingAsAdmin();

        foreach (['historia-clinica', 'autorizacion-tratamiento'] as $tpl) {
            $this->get(route('documentos.create', $tpl))->assertOk()->assertSee('Selecciona un paciente');
            $this->get(route('documentos.create', [$tpl, 'patient' => $patient->id]))->assertOk()->assertSee('Guardar documento');
        }
        $this->get(route('documentos.create', ['historia-clinica', 'patient' => $patient->id]))->assertSee('Renata Aguilar Vega');
    }

    public function test_unknown_template_is_404(): void
    {
        $this->actingAsAdmin();

        $this->get('/documentos/plantilla-inexistente/crear')->assertNotFound();
        $this->get('/documentos/plantilla-inexistente/vista-previa')->assertNotFound();
    }

    public function test_blank_preview_renders(): void
    {
        $this->actingAsAdmin();

        $this->get(route('documentos.preview', 'historia-clinica'))->assertOk()->assertSee('Datos de identificación');
        $this->get(route('documentos.preview', 'autorizacion-tratamiento'))->assertOk()->assertSee('Desarrollo de tratamiento');
    }

    public function test_medical_history_is_stored_as_a_clinical_file_and_can_be_printed(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->post(route('documentos.store', 'historia-clinica'), $this->historia(['patient_id' => $patient->id]))
            ->assertRedirect(route('documentos.index'))->assertSessionHas('success');

        $file = ClinicalFile::query()->firstOrFail();
        $this->assertSame(DocumentService::MIME, $file->mime_type);
        $this->assertSame($patient->id, $file->patient_id);
        $this->assertSame('otro', $file->file_type->value);
        Storage::disk('local')->assertExists($file->storage_key);

        $this->get(route('documentos.show', $file->id))->assertOk()->assertSee('Valeria Domínguez Soto')->assertSee('Integridad verificada');
        $this->get(route('documentos.index'))->assertOk()->assertSee($file->display_name);
    }

    public function test_medical_history_syncs_the_medical_record_when_requested(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->post(route('documentos.store', 'historia-clinica'), $this->historia([
            'patient_id' => $patient->id, 'sync_record' => 1, 'conditions' => ['diabetes', 'reacciones_alergicas'],
            'allergies_detail' => 'Látex', 'under_treatment' => 'Metformina 850 mg',
        ]))->assertSessionHasNoErrors();

        $record = $patient->fresh()->medicalRecord;
        $this->assertSame('Látex', $record->allergies);
        $this->assertStringContainsString('Diabetes', $record->medical_history);
        $this->assertStringContainsString('Metformina', $record->medical_history);
    }

    public function test_medical_history_validation_rules(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $url = route('documentos.store', 'historia-clinica');

        $this->post($url, $this->historia(['patient_id' => $patient->id, 'pulse' => 999]))->assertSessionHasErrors('pulse');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'respiration' => 2]))->assertSessionHasErrors('respiration');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'temperature' => 80]))->assertSessionHasErrors('temperature');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'blood_pressure' => '120-80']))->assertSessionHasErrors('blood_pressure');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'birth_date' => '2999-01-01']))->assertSessionHasErrors('birth_date');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'phone' => '123']))->assertSessionHasErrors('phone');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'conditions' => ['inventado']]))->assertSessionHasErrors('conditions.0');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'bad_experience' => 'si']))->assertSessionHasErrors('bad_experience_reason');
        $this->post($url, $this->historia(['patient_id' => $patient->id, 'full_name' => '']))->assertSessionHasErrors('full_name');
        $this->post($url, $this->historia(['patient_id' => '00000000-0000-4000-8000-000000000000']))->assertSessionHasErrors('patient_id');
        $this->post($url, $this->historia())->assertSessionHasErrors('patient_id');

        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_treatment_authorization_requires_diagnosis_or_activity(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->post(route('documentos.store', 'autorizacion-tratamiento'), ['patient_id' => $patient->id])->assertSessionHasErrors('diagnoses');
        $this->post(route('documentos.store', 'autorizacion-tratamiento'), ['patient_id' => $patient->id, 'diagnoses' => [['diagnosis' => '', 'date' => '']]])
            ->assertSessionHasErrors('diagnoses');
        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_treatment_authorization_saves_consent_type_file(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $this->post(route('documentos.store', 'autorizacion-tratamiento'), $this->autorizacion([
            'patient_id' => $patient->id,
            'fluoride' => [['year' => 2024, 'details' => 'Barniz de flúor']],
        ]))->assertSessionHasNoErrors();

        $file = ClinicalFile::query()->firstOrFail();
        $this->assertSame('consentimiento', $file->file_type->value);
        $this->assertSame(0, ConsentAcceptance::query()->count(), 'El documento solo no equivale a aceptación.');

        $this->get(route('documentos.show', $file->id))->assertOk()->assertSee('Resina en 26')->assertSee('Caries en 26')->assertSee('Barniz de flúor');
    }

    public function test_acceptance_can_be_registered_together_with_the_authorization(): void
    {
        $patient = $this->makePatient(['full_name' => 'Daniela Vargas Pineda']);
        $this->actingAsAdmin();

        $this->post(route('documentos.store', 'autorizacion-tratamiento'), $this->autorizacion([
            'patient_id' => $patient->id, 'register_acceptance' => 1, 'accepted_by_name' => 'Daniela Vargas Pineda', 'acceptance_method' => 'verbal_registrada',
        ]))->assertSessionHasNoErrors();

        $c = ConsentAcceptance::query()->firstOrFail();
        $this->assertSame(ClinicalFile::query()->firstOrFail()->id, $c->clinical_file_id);
        $this->assertSame('verbal_registrada', $c->method);
        $this->assertSame(ClinicalFile::query()->first()->sha256, $c->content_hash);
    }

    public function test_acceptance_fields_and_dates_are_validated(): void
    {
        $patient = $this->makePatient();
        $minor = $this->makePatient(['birth_date' => now()->subYears(7)->format('Y-m-d'), 'full_name' => 'Sofía Menor']);
        $this->actingAsAdmin();
        $url = route('documentos.store', 'autorizacion-tratamiento');

        $this->post($url, $this->autorizacion(['patient_id' => $patient->id, 'register_acceptance' => 1]))
            ->assertSessionHasErrors(['accepted_by_name', 'acceptance_method']);
        $this->post($url, $this->autorizacion(['patient_id' => $patient->id, 'activities' => [['date' => now()->addDays(3)->format('Y-m-d'), 'activity' => 'Futuro']]]))
            ->assertSessionHasErrors('activities.0.date');
        $this->post($url, $this->autorizacion(['patient_id' => $patient->id, 'activities' => [['date' => now()->format('Y-m-d'), 'activity' => '']]]))
            ->assertSessionHasErrors('activities.0.activity');
        $this->post($url, $this->autorizacion(['patient_id' => $patient->id, 'fluoride' => [['year' => 1800, 'details' => 'x']]]))
            ->assertSessionHasErrors('fluoride.0.year');
        $this->post($url, $this->autorizacion(['patient_id' => $minor->id, 'register_acceptance' => 1, 'accepted_by_name' => 'Sofía Menor', 'acceptance_method' => 'verbal_registrada']))
            ->assertSessionHasErrors('accepted_by_name');

        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_generated_documents_are_not_downloadable_as_raw_json(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->post(route('documentos.store', 'historia-clinica'), $this->historia(['patient_id' => $patient->id]));
        $file = ClinicalFile::query()->firstOrFail();

        $this->get(app(\App\Services\Expediente\FileService::class)->signedUrl($file))->assertNotFound();
    }

    public function test_generated_documents_appear_in_the_patient_files_tab(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->post(route('documentos.store', 'historia-clinica'), $this->historia(['patient_id' => $patient->id]));
        $file = ClinicalFile::query()->firstOrFail();

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'archivos']))
            ->assertOk()->assertSee(route('documentos.show', $file->id), false);
    }

    public function test_tampered_document_is_flagged(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->post(route('documentos.store', 'historia-clinica'), $this->historia(['patient_id' => $patient->id]));
        $file = ClinicalFile::query()->firstOrFail();

        Storage::disk('local')->put($file->storage_key, json_encode(['template' => 'historia-clinica', 'data' => ['full_name' => 'Alterado'], 'patient' => ['id' => $patient->id, 'folio' => 'X', 'full_name' => 'Alterado']]));

        $this->get(route('documentos.show', $file->id))->assertOk()->assertSee('La huella del documento no coincide');
    }

    public function test_doctor_without_documentos_permission_is_blocked(): void
    {
        $this->actingAsDoctor();

        if ($this->doctorUser()->hasPermission('documentos', 'ver')) {
            $this->get(route('documentos.index'))->assertOk();
        } else {
            $this->get(route('documentos.index'))->assertForbidden();
        }
    }

    public function test_receptionist_cannot_create_documents(): void
    {
        $patient = $this->makePatient();
        $this->actingAsReceptionist();

        $this->post(route('documentos.store', 'historia-clinica'), $this->historia(['patient_id' => $patient->id]))->assertForbidden();
    }
}

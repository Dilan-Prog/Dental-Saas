<?php

namespace Tests\Feature\Expediente;

use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAddendum;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ClinicalNoteTest extends ExpedienteTestCase
{
    public function test_simple_note_is_saved_as_draft_with_a_new_encounter(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);

        $this->assertSame('borrador', $note->status->value);
        $this->assertNull($note->content_hash);
        $this->assertNotNull($note->encounter_id);
        $this->assertDatabaseHas('medical_records', ['patient_id' => $patient->id]);
        $this->assertDatabaseHas('clinical_encounters', ['id' => $note->encounter_id]);
    }

    public function test_simple_note_requires_treatment_and_text_and_valid_tooth(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.notas.store', $patient->id), [
            'note_type' => 'simple',
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'tooth_fdi' => 99,
        ])->assertSessionHasErrors(['treatment_id', 'objective', 'tooth_fdi']);

        $this->assertSame(0, ClinicalNote::query()->count());
    }

    public function test_responsible_doctor_must_be_a_doctor(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.notas.store', $patient->id), [
            'note_type' => 'simple',
            'responsible_doctor_member_id' => $this->memberId($this->userByEmail('recepcion@dental.test')),
            'treatment_id' => $this->makeTreatment()->id,
            'objective' => 'Texto',
        ])->assertSessionHasErrors('responsible_doctor_member_id');
    }

    public function test_treatment_from_another_catalog_entry_must_exist(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.notas.store', $patient->id), [
            'note_type' => 'simple',
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => '00000000-0000-4000-8000-000000000000',
            'objective' => 'Texto',
        ])->assertSessionHasErrors('treatment_id');
    }

    public function test_soap_note_needs_at_least_one_section_and_all_four_to_sign(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $payload = ['note_type' => 'soap', 'responsible_doctor_member_id' => $this->doctorMemberId()];

        $this->post(route('expediente.notas.store', $patient->id), $payload)->assertSessionHasErrors('subjective');

        $this->post(route('expediente.notas.store', $patient->id), $payload + ['subjective' => 'Dolor', 'action' => 'firmar'])
            ->assertSessionHasErrors(['objective', 'analysis', 'plan']);

        $this->assertSame(0, ClinicalNote::query()->count());
    }

    public function test_only_the_responsible_doctor_can_sign_and_signing_sets_hash(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);

        // El admin no es el doctor responsable.
        $this->actingAsAdmin();
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]))->assertSessionHas('error');
        $this->assertSame('borrador', $note->fresh()->status->value);

        // El doctor responsable sí puede.
        $this->actingAsDoctor();
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]))->assertRedirect()->assertSessionHas('success');

        $note->refresh();
        $this->assertSame('firmada', $note->status->value);
        $this->assertSame($this->doctorMemberId(), $note->signed_by_member_id);
        $this->assertNotNull($note->signed_at);
        $this->assertSame(64, strlen($note->content_hash));
        $this->assertSame($note->computeHash(), $note->content_hash);
    }

    public function test_note_can_be_created_and_signed_in_one_step(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('expediente.notas.store', $patient->id), [
            'note_type' => 'simple',
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $this->makeTreatment()->id,
            'objective' => 'Resina en 26 sin complicaciones.',
            'action' => 'firmar',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('firmada', ClinicalNote::query()->firstOrFail()->status->value);
    }

    public function test_signed_note_cannot_be_edited_or_deleted_through_the_app(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));

        $this->put(route('expediente.notas.update', [$patient->id, $note->id]), [
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $note->treatment_id,
            'objective' => 'Intento de alterar una nota firmada',
        ])->assertSessionHas('error');

        $this->delete(route('expediente.notas.destroy', [$patient->id, $note->id]))->assertSessionHas('error');

        $note->refresh();
        $this->assertSame('Procedimiento realizado sin complicaciones.', $note->objective);
        $this->assertTrue($note->isSigned());
    }

    public function test_database_trigger_blocks_direct_changes_to_a_signed_note(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));

        $this->expectException(QueryException::class);
        DB::table('clinical_notes')->where('id', $note->id)->update(['objective' => 'manipulada']);
    }

    public function test_integrity_hash_detects_tampering_of_unsigned_fields_only_via_model(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));
        $note->refresh();

        $this->assertSame($note->content_hash, $note->computeHash());

        $note->objective = 'otro texto';
        $this->assertNotSame($note->content_hash, $note->computeHash());
    }

    public function test_draft_can_be_updated_and_deleted(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);

        $this->put(route('expediente.notas.update', [$patient->id, $note->id]), [
            'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $note->treatment_id,
            'objective' => 'Texto corregido',
            'action' => 'guardar',
        ])->assertRedirect();
        $this->assertSame('Texto corregido', $note->fresh()->objective);

        $this->delete(route('expediente.notas.destroy', [$patient->id, $note->id]))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('clinical_notes', ['id' => $note->id]);
    }

    public function test_addendum_requires_a_signed_note_and_versions_increase(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);

        $this->post(route('expediente.notas.addendum', [$patient->id, $note->id]), ['content' => 'Aclaración importante', 'reason' => 'Error de captura'])
            ->assertSessionHas('error');
        $this->assertSame(0, ClinicalNoteAddendum::query()->count());

        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));

        foreach ([1, 2] as $expected) {
            $this->post(route('expediente.notas.addendum', [$patient->id, $note->id]), ['content' => 'Aclaración número '.$expected, 'reason' => 'Corrección '.$expected])
                ->assertSessionHas('success');
        }

        $this->assertSame([1, 2], ClinicalNoteAddendum::query()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertSame(64, strlen(ClinicalNoteAddendum::query()->first()->content_hash));
        // La nota original no cambia.
        $this->assertSame('Procedimiento realizado sin complicaciones.', $note->fresh()->objective);
    }

    public function test_addendum_validation(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);
        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));

        $this->post(route('expediente.notas.addendum', [$patient->id, $note->id]), ['content' => 'a', 'reason' => ''])
            ->assertSessionHasErrors(['content', 'reason']);
    }

    public function test_note_pages_render_for_draft_and_signed(): void
    {
        $patient = $this->makePatient();
        $note = $this->createDraftNote($patient);

        $this->get(route('expediente.notas.create', $patient->id))->assertOk();
        $this->get(route('expediente.notas.create', [$patient->id, 'tipo' => 'simple']))->assertOk();
        $this->get(route('expediente.notas.show', [$patient->id, $note->id]))->assertOk()->assertSee('Borrador');

        $this->post(route('expediente.notas.sign', [$patient->id, $note->id]));
        $this->get(route('expediente.notas.show', [$patient->id, $note->id]))->assertOk()->assertSee('integridad verificada');
    }

    public function test_note_of_another_patient_is_not_reachable_through_this_patient(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $note = $this->createDraftNote($a);

        $this->get(route('expediente.notas.show', [$b->id, $note->id]))->assertNotFound();
        $this->post(route('expediente.notas.sign', [$b->id, $note->id]))->assertNotFound();
    }

    public function test_note_can_be_linked_to_an_existing_encounter_and_rejects_foreign_ones(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $first = $this->createDraftNote($a);

        $this->actingAsDoctor();
        $payload = fn ($encounterId) => [
            'note_type' => 'simple', 'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $first->treatment_id, 'objective' => 'Segunda nota', 'encounter_id' => $encounterId,
        ];

        $this->post(route('expediente.notas.store', $a->id), $payload($first->encounter_id))->assertSessionHasNoErrors();
        $this->assertSame(2, ClinicalNote::query()->where('encounter_id', $first->encounter_id)->count());

        $this->post(route('expediente.notas.store', $b->id), $payload($first->encounter_id))->assertSessionHasErrors('encounter_id');
    }

    public function test_notes_listing_filters_by_status(): void
    {
        $patient = $this->makePatient();
        $this->createDraftNote($patient, ['objective' => 'Nota en borrador visible']);

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'notas', 'estado' => 'borrador']))
            ->assertOk()->assertSee('Nota en borrador visible');
        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'notas', 'estado' => 'firmada']))
            ->assertOk()->assertDontSee('Nota en borrador visible');
    }
}

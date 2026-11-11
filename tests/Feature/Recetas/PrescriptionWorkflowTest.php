<?php

namespace Tests\Feature\Recetas;

use App\Enums\OutboxStatus;
use App\Enums\SigningStatus;
use App\Models\OutboxEvent;
use App\Models\Prescription;
use App\Models\PrescriptionDelivery;
use App\Models\PrescriptionItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PrescriptionWorkflowTest extends RecetasTestCase
{
    public function test_doctor_saves_a_draft_with_consecutive_folio_and_lines(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $payload = $this->payload($patient, ['items' => [
            $this->item(),
            $this->item(['medication_name' => 'Clorhexidina 0.12%', 'presentation' => 'Enjuague', 'dose' => '15 ml', 'frequency' => 'cada 12 horas']),
        ]]);

        $this->post(route('recetas.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $rx = Prescription::query()->firstOrFail();
        $this->assertSame('RX-0001', $rx->folio);
        $this->assertSame(SigningStatus::Borrador, $rx->status);
        $this->assertNull($rx->signed_at);
        $this->assertNull($rx->content_hash);
        $this->assertSame([1, 2], $rx->items->pluck('line_number')->all());
        $this->assertSame('Clorhexidina 0.12%', $rx->items[1]->medication_name);

        $this->post(route('recetas.store'), $this->payload($patient))->assertSessionHasNoErrors();
        $this->assertSame('RX-0002', Prescription::query()->orderByDesc('folio')->value('folio'));
    }

    public function test_blank_rows_are_discarded_and_catalog_data_is_copied(): void
    {
        $med = $this->makeGlobalMedication(['name' => 'Amoxicilina 500 mg', 'strength' => '500 mg', 'presentation' => 'Cápsulas']);
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $payload = $this->payload($patient, ['items' => [
            ['medication_id' => $med->id, 'dose' => '1 cápsula', 'frequency' => 'cada 8 horas'], // nombre vacío: se toma del catálogo
            ['medication_name' => '', 'dose' => '', 'frequency' => ''],                              // renglón en blanco
        ]]);

        $this->post(route('recetas.store'), $payload)->assertSessionHasNoErrors();

        $rx = Prescription::query()->firstOrFail();
        $this->assertCount(1, $rx->items);
        $this->assertSame('Amoxicilina 500 mg', $rx->items[0]->medication_name);
        $this->assertSame('Cápsulas', $rx->items[0]->presentation);
        // Los medicamentos del catálogo global quedan como snapshot (la FK compuesta exige organización).
        $this->assertNull($rx->items[0]->medication_id);
    }

    public function test_own_catalog_medication_stays_linked(): void
    {
        $own = $this->makeMedication(['name' => 'Propio 100 mg', 'strength' => '100 mg', 'presentation' => 'Tabletas']);
        $this->actingAsDoctor();

        $this->post(route('recetas.store'), $this->payload($this->makePatient(), ['items' => [
            ['medication_id' => $own->id, 'dose' => '1 tableta', 'frequency' => 'cada 12 horas'],
        ]]))->assertSessionHasNoErrors();

        $item = Prescription::query()->firstOrFail()->items[0];
        $this->assertSame($own->id, $item->medication_id);
        $this->assertSame('Propio 100 mg', $item->medication_name);
    }

    public function test_doctor_signs_and_emits_from_the_form(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))
            ->assertSessionHasNoErrors();

        $rx = Prescription::query()->firstOrFail();
        $this->assertSame(SigningStatus::Firmada, $rx->status);
        $this->assertSame('Dra. Mariana Reyes', $rx->doctor_name_snapshot);
        $this->assertSame('1234567', $rx->doctor_license_snapshot);
        $this->assertNotNull($rx->signed_at);
        $this->assertSame(64, strlen($rx->content_hash));
        $this->assertTrue($this->service()->verifyIntegrity($rx));
    }

    public function test_vista_action_saves_draft_and_opens_print_view(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $response = $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'vista']));
        $rx = Prescription::query()->firstOrFail();
        $response->assertRedirect(route('recetas.print', $rx));
        $this->assertSame(SigningStatus::Borrador, $rx->status);
    }

    public function test_only_the_prescribing_doctor_can_sign(): void
    {
        $patient = $this->makePatient();
        $draft = $this->makeDraft($patient);

        // El administrador tiene el permiso, pero no es el doctor que prescribe.
        $this->actingAsAdmin();
        $this->post(route('recetas.emit', $draft))->assertSessionHas('error');
        $this->assertSame(SigningStatus::Borrador, $draft->fresh()->status);

        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))
            ->assertSessionHasErrors('doctor_member_id');
        $this->assertSame(1, $this->rxCount());
    }

    public function test_emit_route_signs_a_saved_draft(): void
    {
        $draft = $this->makeDraft();
        $this->actingAsDoctor();

        $this->post(route('recetas.emit', $draft))
            ->assertRedirect(route('recetas.show', $draft))
            ->assertSessionHas('success');

        $this->assertSame(SigningStatus::Firmada, $draft->fresh()->status);
    }

    public function test_emit_requires_license_diagnosis_and_active_patient(): void
    {
        // Sin cédula
        $draft = $this->makeDraft();
        DB::table('doctor_profiles')->where('member_id', $this->doctorMemberId())->update(['professional_license' => null]);
        $this->actingAsDoctor();
        $this->post(route('recetas.emit', $draft))->assertSessionHas('error');
        $this->assertSame(SigningStatus::Borrador, $draft->fresh()->status);
        $this->post(route('recetas.store'), $this->payload($this->makePatient(['full_name' => 'Otra Persona López']), ['action' => 'emitir']))
            ->assertSessionHasErrors('doctor_member_id');
        DB::table('doctor_profiles')->where('member_id', $this->doctorMemberId())->update(['professional_license' => '1234567']);

        // Sin diagnóstico
        $noDx = $this->makeDraft($this->makePatient(['full_name' => 'Sin Diagnóstico Pérez']), ['diagnosis' => null]);
        $this->post(route('recetas.emit', $noDx))->assertSessionHas('error');
        $this->assertSame(SigningStatus::Borrador, $noDx->fresh()->status);
        $this->post(route('recetas.store'), $this->payload($this->makePatient(['full_name' => 'Otro Sin Dx Ruiz']), ['action' => 'emitir', 'diagnosis' => '']))
            ->assertSessionHasErrors('diagnosis');

        // Paciente dado de baja después de crear el borrador
        $inactive = $this->makeDraft($this->makePatient(['full_name' => 'Baja Reciente Díaz']));
        DB::table('patients')->where('id', $inactive->patient_id)->update(['status' => 'inactive']);
        $this->post(route('recetas.emit', $inactive))->assertSessionHas('error');
        $this->assertSame(SigningStatus::Borrador, $inactive->fresh()->status);
    }

    public function test_draft_can_be_updated_and_items_are_replaced(): void
    {
        $draft = $this->makeDraft();
        $folio = $draft->folio;
        $this->actingAsDoctor();

        $payload = $this->payload($draft->patient, [
            'diagnosis' => 'Pericoronitis en 48',
            'items' => [$this->item(['medication_name' => 'Amoxicilina 500 mg', 'dose' => '1 cápsula'])],
        ]);
        $this->put(route('recetas.update', $draft), $payload)->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame($folio, $draft->folio);
        $this->assertSame('Pericoronitis en 48', $draft->diagnosis);
        $this->assertCount(1, $draft->items);
        $this->assertSame('Amoxicilina 500 mg', $draft->items[0]->medication_name);
        $this->assertSame(1, PrescriptionItem::query()->where('prescription_id', $draft->id)->count());
    }

    public function test_signed_prescription_is_immutable_from_the_app_and_the_database(): void
    {
        $rx = $this->makeSigned();
        $this->actingAsDoctor();

        $this->put(route('recetas.update', $rx), $this->payload($rx->patient, ['diagnosis' => 'Cambio no permitido']))
            ->assertRedirect(route('recetas.show', $rx))->assertSessionHas('error');
        $this->assertSame('Caries dental en 26 sin compromiso pulpar', $rx->fresh()->diagnosis);

        $this->delete(route('recetas.destroy', $rx))->assertSessionHas('error');
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id]);

        // Triggers de la BD (SQLSTATE 45000)
        try {
            DB::table('prescriptions')->where('id', $rx->id)->update(['diagnosis' => 'x']);
            $this->fail('La BD debía rechazar la modificación de una receta firmada.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
        try {
            DB::table('prescriptions')->where('id', $rx->id)->delete();
            $this->fail('La BD debía rechazar el borrado de una receta firmada.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
        try {
            DB::table('prescription_items')->where('prescription_id', $rx->id)->delete();
            $this->fail('La BD debía rechazar el borrado de conceptos de una receta firmada.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
    }

    public function test_draft_can_be_deleted_but_only_drafts(): void
    {
        $draft = $this->makeDraft();
        $this->actingAsDoctor();

        $this->delete(route('recetas.destroy', $draft))->assertRedirect(route('recetas.index'))->assertSessionHas('success');
        $this->assertDatabaseMissing('prescriptions', ['id' => $draft->id]);
        $this->assertSame(0, PrescriptionItem::query()->count());
    }

    public function test_repeat_creates_a_new_draft_from_a_signed_prescription(): void
    {
        $rx = $this->makeSigned(null, ['items' => [$this->item(), $this->item(['medication_name' => 'Clorhexidina 0.12%'])]]);
        $this->actingAsDoctor();

        $response = $this->post(route('recetas.repeat', $rx));

        $new = Prescription::query()->where('repeated_from_id', $rx->id)->firstOrFail();
        $response->assertRedirect(route('recetas.edit', $new));
        $this->assertNotSame($rx->folio, $new->folio);
        $this->assertSame(SigningStatus::Borrador, $new->status);
        $this->assertSame($rx->patient_id, $new->patient_id);
        $this->assertSame(now()->toDateString(), $new->issued_on->toDateString());
        $this->assertCount(2, $new->items);
        $this->assertNull($new->content_hash);

        // Un borrador no se puede repetir.
        $this->post(route('recetas.repeat', $new))->assertSessionHas('error');
        $this->assertSame(0, Prescription::query()->where('repeated_from_id', $new->id)->count());
    }

    public function test_repeat_is_rejected_for_inactive_patient(): void
    {
        $rx = $this->makeSigned();
        DB::table('patients')->where('id', $rx->patient_id)->update(['status' => 'inactive']);
        $this->actingAsDoctor();

        $this->post(route('recetas.repeat', $rx))->assertSessionHas('error');
        $this->assertSame(1, $this->rxCount());
    }

    public function test_send_by_email_whatsapp_and_print_records_delivery_and_outbox(): void
    {
        $rx = $this->makeSigned();
        $this->actingAsDoctor();

        $this->post(route('recetas.send', $rx), ['channel' => 'email'])->assertSessionHas('success');
        $this->post(route('recetas.send', $rx), ['channel' => 'whatsapp'])->assertSessionHas('success');
        $this->post(route('recetas.send', $rx), ['channel' => 'impresion'])->assertSessionHas('success');

        $this->assertSame(3, PrescriptionDelivery::query()->where('prescription_id', $rx->id)->count());
        $this->assertSame(3, OutboxEvent::query()->where('aggregate_id', $rx->id)->count());

        $email = PrescriptionDelivery::query()->where('channel', 'email')->firstOrFail();
        $this->assertNull($email->sent_at);
        $event = $email->outboxEvent;
        $this->assertSame(OutboxStatus::Pendiente, $event->status);
        $this->assertSame('prescription.deliver', $event->event_type);
        $this->assertSame('valeria@example.com', $event->payload['destination']);
        $this->assertArrayNotHasKey('diagnosis', $event->payload);

        $print = PrescriptionDelivery::query()->where('channel', 'impresion')->firstOrFail();
        $this->assertNotNull($print->sent_at);
        $this->assertSame(OutboxStatus::Enviado, $print->outboxEvent->status);
        $this->assertSame($this->doctorUser()->id, $print->actor_user_id);
    }

    public function test_send_validates_channel_status_and_contact_data(): void
    {
        $noContact = $this->makePatient(['full_name' => 'Sin Contacto Vega', 'email' => null, 'phone' => '12345']);
        $rx = $this->makeSigned($noContact);
        $draft = $this->makeDraft($this->makePatient(['full_name' => 'Borrador Solo Pérez']));
        $this->actingAsDoctor();

        $this->post(route('recetas.send', $rx), ['channel' => 'email'])->assertSessionHas('error');
        $this->post(route('recetas.send', $rx), ['channel' => 'whatsapp'])->assertSessionHas('error');
        $this->post(route('recetas.send', $rx), ['channel' => 'paloma'])->assertSessionHasErrors('channel');
        $this->post(route('recetas.send', $rx), [])->assertSessionHasErrors('channel');
        $this->post(route('recetas.send', $draft), ['channel' => 'impresion'])->assertSessionHas('error');

        $this->assertSame(0, PrescriptionDelivery::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_print_and_pdf_views(): void
    {
        $signed = $this->makeSigned();
        $draft = $this->makeDraft($this->makePatient(['full_name' => 'Paciente Borrador Núñez']));
        $this->actingAsDoctor();

        $this->get(route('recetas.print', $signed))
            ->assertOk()
            ->assertSee($signed->folio)
            ->assertSee('Valeria Domínguez Soto')
            ->assertSee('Dra. Mariana Reyes')
            ->assertSee('Céd. Prof. 1234567')
            ->assertSee('Imprimir / Guardar como PDF')
            ->assertDontSee('BORRADOR');

        $this->get(route('recetas.print', $draft))->assertOk()->assertSee('BORRADOR')->assertSee('Borrador sin validez');

        $this->get(route('recetas.pdf', $signed))->assertRedirect(route('recetas.print', ['prescription' => $signed, 'auto' => 1]));
        $this->get(route('recetas.print', ['prescription' => $signed, 'auto' => 1]))->assertOk()->assertSee('window.print()', false);
    }

    public function test_document_shows_patient_age_at_issue_date_and_allergy_warning_in_form(): void
    {
        $patient = $this->makePatient(['birth_date' => now()->subYears(34)->subDays(10)->toDateString()], 'Alergia a la penicilina');
        $rx = $this->makeSigned($patient);
        $this->actingAsDoctor();

        $this->get(route('recetas.show', $rx))->assertSee('34 años');
        $this->getJson(route('recetas.pacientes.buscar', ['q' => 'Valeria']))
            ->assertOk()
            ->assertJsonPath('0.allergies', 'Alergia a la penicilina')
            ->assertJsonPath('0.age', 34);
    }

    public function test_patient_search_returns_only_active_patients(): void
    {
        $this->makePatient(['full_name' => 'Activa Uno Pérez']);
        $inactive = $this->makePatient(['full_name' => 'Inactiva Dos Pérez']);
        DB::table('patients')->where('id', $inactive->id)->update(['status' => 'inactive']);
        $gone = $this->makePatient(['full_name' => 'Borrada Tres Pérez']);
        DB::table('patients')->where('id', $gone->id)->update(['deleted_at' => now()]);
        $this->actingAsDoctor();

        $names = collect($this->getJson(route('recetas.pacientes.buscar', ['q' => 'Pérez']))->assertOk()->json())->pluck('name');
        $this->assertSame(['Activa Uno Pérez'], $names->all());
    }

    public function test_sequential_folios_do_not_skip_when_a_save_fails(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('recetas.store'), $this->payload($patient, ['items' => []]))->assertSessionHasErrors('items');
        $this->post(route('recetas.store'), $this->payload($patient))->assertSessionHasNoErrors();

        $this->assertSame('RX-0001', Prescription::query()->value('folio'));
    }
}

<?php

namespace Tests\Feature\QA\Billing;

use App\Enums\SigningStatus;
use App\Models\FolioSequence;
use App\Models\Prescription;
use App\Models\PrescriptionDelivery;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Recetas\RecetasTestCase;

/** QA: validación y coherencia del módulo de recetas. */
class PrescriptionQaTest extends RecetasTestCase
{
    private function store(array $over = [], ?\App\Models\Patient $patient = null)
    {
        $patient ??= $this->makePatient();

        return $this->post(route('recetas.store'), $this->payload($patient, $over));
    }

    public function test_form_validation_boundaries(): void
    {
        $this->actingAsDoctor();
        $cases = [
            ['diagnosis' => str_repeat('d', 1001)],
            ['general_instructions' => str_repeat('i', 2001)],
            ['issued_on' => now()->addDay()->toDateString()],
            ['issued_on' => now()->subYears(2)->toDateString()],
            ['issued_on' => '14/02/2026'],
            ['action' => 'borrar'],
            ['patient_id' => '00000000-0000-4000-8000-000000000000'],
            ['doctor_member_id' => $this->memberId($this->userByEmail('recepcion@dental.test'))],
            ['items' => array_fill(0, 21, $this->item())],
            ['items' => [$this->item(['dose' => ''])]],
            ['items' => [$this->item(['frequency' => ''])]],
            ['items' => [$this->item(['medication_name' => str_repeat('m', 201)])]],
            ['items' => [$this->item(['instructions' => str_repeat('x', 1001)])]],
            ['items' => [$this->item(['medication_id' => 'no-uuid'])]],
        ];
        foreach ($cases as $i => $over) {
            $this->store($over);
            $this->assertTrue(session()->has('errors'), "Caso $i debio rechazarse: ".json_encode(array_keys($over)));
        }
        $this->assertSame(0, $this->rxCount());
        // Un formulario rechazado no consume folios.
        $this->assertSame(0, (int) FolioSequence::where('document_type', 'prescription')->value('last_value'));
    }

    public function test_inactive_or_deleted_patient_cannot_receive_a_prescription(): void
    {
        $patient = $this->makePatient();
        $patient->delete();
        $this->actingAsDoctor();

        $this->post(route('recetas.store'), $this->payload($patient))->assertSessionHasErrors('patient_id');
        $this->assertSame(0, $this->rxCount());
    }

    public function test_only_the_prescribing_doctor_with_license_can_emit(): void
    {
        $patient = $this->makePatient();

        // Administrador no es el doctor que prescribe.
        $this->actingAsAdmin();
        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))
            ->assertSessionHasErrors('doctor_member_id');
        $this->assertSame(0, $this->rxCount());

        // Recepción no tiene el permiso emitir_recetas.
        $this->actingAsReceptionist();
        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))->assertForbidden();

        // Doctor sin cédula no puede emitir.
        DB::table('doctor_profiles')->where('member_id', $this->doctorMemberId())->update(['professional_license' => null]);
        $this->actingAsDoctor();
        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))
            ->assertSessionHasErrors('doctor_member_id');
        $this->assertSame(0, $this->rxCount());

        // Con cédula sí.
        DB::table('doctor_profiles')->where('member_id', $this->doctorMemberId())->update(['professional_license' => '12345678']);
        $this->post(route('recetas.store'), $this->payload($patient, ['action' => 'emitir']))->assertSessionHasNoErrors();
        $rx = Prescription::firstOrFail();
        $this->assertSame(SigningStatus::Firmada, $rx->status);
        $this->assertSame('12345678', $rx->doctor_license_snapshot);
        $this->assertNotEmpty($rx->doctor_name_snapshot);
        $this->assertSame(64, strlen((string) $rx->content_hash));
    }

    public function test_emit_requires_diagnosis(): void
    {
        $this->actingAsDoctor();
        $this->store(['action' => 'emitir', 'diagnosis' => ''])->assertSessionHasErrors('diagnosis');
        $this->assertSame(0, $this->rxCount());
    }

    public function test_signed_prescription_is_immutable_over_http_and_in_the_database(): void
    {
        $rx = $this->makeSigned();
        $this->actingAsDoctor();

        $payload = $this->payload($rx->patient, ['diagnosis' => 'Otro diagnóstico']);
        $this->put(route('recetas.update', $rx), $payload)->assertSessionHas('error');
        $this->get(route('recetas.edit', $rx))->assertRedirect();
        $this->delete(route('recetas.destroy', $rx));
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'status' => 'firmada']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('prescription_items')->where('prescription_id', $rx->id)->update(['dose' => 'hackeada']);
    }

    public function test_catalog_medication_links_and_global_medication_falls_back_to_snapshot(): void
    {
        $org = $this->makeMedication();
        $global = $this->makeGlobalMedication();
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->post(route('recetas.store'), $this->payload($patient, ['items' => [
            $this->item(['medication_id' => $org->id, 'medication_name' => 'Paracetamol 500 mg']),
            $this->item(['medication_id' => $global->id, 'medication_name' => 'Amoxicilina 500 mg']),
        ]]))->assertSessionHasNoErrors();

        $rx = Prescription::firstOrFail();
        $this->assertSame($org->id, $rx->items[0]->medication_id);
        // Limitación conocida: la FK compuesta no admite medicamentos globales (organization_id NULL).
        $this->assertNull($rx->items[1]->medication_id);
        $this->assertSame('Amoxicilina 500 mg', $rx->items[1]->medication_name);
    }

    public function test_repeat_creates_a_new_draft_without_touching_the_original(): void
    {
        $rx = $this->makeSigned();
        $this->actingAsDoctor();

        $this->post(route('recetas.repeat', $rx))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->rxCount());
        $copy = Prescription::where('id', '!=', $rx->id)->firstOrFail();
        $this->assertSame(SigningStatus::Borrador, $copy->status);
        $this->assertSame($rx->id, $copy->repeated_from_id);
        $this->assertNotSame($rx->folio, $copy->folio);
        $this->assertNull($copy->signed_at);
        $this->assertNull($copy->content_hash);
        $this->assertSame($rx->items->count(), $copy->items->count());
        $this->assertSame(SigningStatus::Firmada, $rx->fresh()->status);
    }

    public function test_send_validates_channel_and_rejects_drafts(): void
    {
        $signed = $this->makeSigned();
        $draft = $this->makeDraft();
        $this->actingAsDoctor();

        $this->post(route('recetas.send', $signed), ['channel' => 'paloma'])->assertSessionHasErrors('channel');
        $this->post(route('recetas.send', $signed), [])->assertSessionHasErrors('channel');
        $this->assertSame(0, PrescriptionDelivery::count());

        $this->post(route('recetas.send', $draft), ['channel' => 'email']);
        $this->assertSame(0, PrescriptionDelivery::where('prescription_id', $draft->id)->count());

        $this->post(route('recetas.send', $signed), ['channel' => 'email'])->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionDelivery::where('prescription_id', $signed->id)->count());
    }

    public function test_folios_are_consecutive_and_unique(): void
    {
        $this->actingAsDoctor();
        $patient = $this->makePatient();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('recetas.store'), $this->payload($patient));
        }
        $folios = Prescription::orderBy('folio')->pluck('folio')->all();
        $this->assertSame(['RX-0001', 'RX-0002', 'RX-0003', 'RX-0004', 'RX-0005'], $folios);
    }
}

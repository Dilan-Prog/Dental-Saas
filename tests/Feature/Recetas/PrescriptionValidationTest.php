<?php

namespace Tests\Feature\Recetas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Validaciones de formulario y de dominio al crear/editar recetas. */
class PrescriptionValidationTest extends RecetasTestCase
{
    private function submit(array $over = [], ?array $items = null): \Illuminate\Testing\TestResponse
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $payload = $this->payload($patient, $over);
        if ($items !== null) {
            $payload['items'] = $items;
        }

        return $this->post(route('recetas.store'), $payload);
    }

    public function test_valid_payload_passes(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rxCount());
    }

    public function test_required_fields(): void
    {
        $this->actingAsDoctor();
        $this->post(route('recetas.store'), [])
            ->assertSessionHasErrors(['patient_id', 'doctor_member_id', 'issued_on', 'items']);
        $this->assertSame(0, $this->rxCount());
    }

    public function test_at_least_one_medication_is_required(): void
    {
        $this->submit([], [])->assertSessionHasErrors('items');
        $this->submit([], [['medication_name' => '', 'dose' => '', 'frequency' => '']])->assertSessionHasErrors('items');
        $this->assertSame(0, $this->rxCount());
    }

    public function test_dose_frequency_and_name_are_required_per_item(): void
    {
        $this->submit([], [$this->item(['dose' => '', 'frequency' => '', 'medication_name' => 'Ibuprofeno'])])
            ->assertSessionHasErrors(['items.0.dose', 'items.0.frequency']);
        $this->submit([], [$this->item(), $this->item(['medication_name' => '', 'dose' => '1 tableta'])])
            ->assertSessionHasErrors(['items.1.medication_name']);
        $this->assertSame(0, $this->rxCount());
    }

    public function test_error_messages_are_in_spanish_and_mention_the_line(): void
    {
        $response = $this->submit([], [$this->item(), $this->item(['dose' => '', 'frequency' => '1 vez'])]);
        $response->assertSessionHasErrors('items.1.dose');
        $this->assertSame('Indique la dosis del medicamento 2.', session('errors')->first('items.1.dose'));
    }

    public function test_date_rules(): void
    {
        $this->submit(['issued_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('issued_on');
        $this->submit(['issued_on' => now()->subYears(2)->toDateString()])->assertSessionHasErrors('issued_on');
        $this->submit(['issued_on' => '31/12/2025'])->assertSessionHasErrors('issued_on');
        $this->submit(['issued_on' => 'no-es-fecha'])->assertSessionHasErrors('issued_on');
        $this->submit(['issued_on' => now()->subMonths(2)->toDateString()])->assertSessionHasNoErrors();
        $this->submit(['issued_on' => now()->toDateString()])->assertSessionHasNoErrors();
    }

    public function test_patient_must_exist_be_active_and_not_anonymized(): void
    {
        $this->submit(['patient_id' => (string) Str::uuid()])->assertSessionHasErrors('patient_id');
        $this->submit(['patient_id' => 'abc'])->assertSessionHasErrors('patient_id');

        $inactive = $this->makePatient(['full_name' => 'Inactivo Uno']);
        DB::table('patients')->where('id', $inactive->id)->update(['status' => 'inactive']);
        $this->post(route('recetas.store'), $this->payload($inactive))->assertSessionHasErrors('patient_id');

        $anon = $this->makePatient(['full_name' => 'Anonimizado Dos']);
        DB::table('patients')->where('id', $anon->id)->update(['anonymized_at' => now()]);
        $this->post(route('recetas.store'), $this->payload($anon))->assertSessionHasErrors('patient_id');

        $deleted = $this->makePatient(['full_name' => 'Eliminado Tres']);
        DB::table('patients')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $this->post(route('recetas.store'), $this->payload($deleted))->assertSessionHasErrors('patient_id');

        $this->assertSame(0, $this->rxCount());
    }

    public function test_doctor_must_be_an_active_doctor(): void
    {
        $adminMember = $this->memberId($this->userByEmail('admin@dental.test')); // sin perfil de doctor
        $this->submit(['doctor_member_id' => $adminMember])->assertSessionHasErrors('doctor_member_id');
        $this->submit(['doctor_member_id' => (string) Str::uuid()])->assertSessionHasErrors('doctor_member_id');

        DB::table('organization_members')->where('id', $this->doctorMemberId())->update(['is_active' => 0]);
        $this->actingAsAdmin();
        $this->post(route('recetas.store'), $this->payload($this->makePatient(['full_name' => 'Paciente Dos'])))
            ->assertSessionHasErrors('doctor_member_id');
        $this->assertSame(0, $this->rxCount());
    }

    public function test_diagnosis_is_required_only_to_emit_and_has_max_length(): void
    {
        $this->submit(['diagnosis' => null])->assertSessionHasNoErrors(); // borrador sin dx
        $this->submit(['diagnosis' => '', 'action' => 'emitir'])->assertSessionHasErrors('diagnosis');
        $this->submit(['diagnosis' => str_repeat('a', 1001)])->assertSessionHasErrors('diagnosis');
        $this->submit(['general_instructions' => str_repeat('a', 2001)])->assertSessionHasErrors('general_instructions');
    }

    public function test_item_field_lengths_and_medication_reference(): void
    {
        $this->submit([], [$this->item(['medication_name' => str_repeat('a', 201)])])->assertSessionHasErrors('items.0.medication_name');
        $this->submit([], [$this->item(['dose' => str_repeat('a', 121)])])->assertSessionHasErrors('items.0.dose');
        $this->submit([], [$this->item(['frequency' => str_repeat('a', 121)])])->assertSessionHasErrors('items.0.frequency');
        $this->submit([], [$this->item(['duration' => str_repeat('a', 121)])])->assertSessionHasErrors('items.0.duration');
        $this->submit([], [$this->item(['presentation' => str_repeat('a', 121)])])->assertSessionHasErrors('items.0.presentation');
        $this->submit([], [$this->item(['route' => str_repeat('a', 61)])])->assertSessionHasErrors('items.0.route');
        $this->submit([], [$this->item(['instructions' => str_repeat('a', 1001)])])->assertSessionHasErrors('items.0.instructions');
        $this->submit([], [$this->item(['medication_id' => 'no-uuid'])])->assertSessionHasErrors('items.0.medication_id');
        $this->submit([], [$this->item(['medication_id' => (string) Str::uuid()])])->assertSessionHasErrors('items.0.medication_id');

        $inactive = $this->makeMedication(['name' => 'Inactivo X', 'status' => 'inactive']);
        $this->submit([], [$this->item(['medication_id' => $inactive->id])])->assertSessionHasErrors('items.0.medication_id');

        $this->assertSame(0, $this->rxCount());
    }

    public function test_maximum_twenty_items(): void
    {
        $this->submit([], array_fill(0, 21, $this->item()))->assertSessionHasErrors('items');
        $this->submit([], array_fill(0, 20, $this->item()))->assertSessionHasNoErrors();
    }

    public function test_invalid_action_is_rejected(): void
    {
        $this->submit(['action' => 'hackear'])->assertSessionHasErrors('action');
    }

    public function test_input_is_trimmed(): void
    {
        $this->submit(['diagnosis' => '   Caries   '], [$this->item(['dose' => '  1 tableta  ', 'medication_name' => '  Ibuprofeno  '])]);
        $rx = \App\Models\Prescription::query()->with('items')->firstOrFail();
        $this->assertSame('Caries', $rx->diagnosis);
        $this->assertSame('1 tableta', $rx->items[0]->dose);
        $this->assertSame('Ibuprofeno', $rx->items[0]->medication_name);
    }

    public function test_validation_errors_keep_old_input_in_the_form(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->from(route('recetas.create'))
            ->post(route('recetas.store'), $this->payload($patient, ['diagnosis' => 'Dx conservado', 'items' => [$this->item(['dose' => ''])]]))
            ->assertRedirect(route('recetas.create'));

        $this->get(route('recetas.create'))
            ->assertSee('Dx conservado')
            ->assertSee('Ibuprofeno 400 mg');
    }
}

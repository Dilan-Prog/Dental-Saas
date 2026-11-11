<?php

namespace Tests\Feature\Expediente;

use App\Models\ToothFinding;

class OdontogramTest extends ExpedienteTestCase
{
    private function setTooth($patient, array $data)
    {
        return $this->post(route('expediente.odontograma.store', $patient->id), $data);
    }

    public function test_default_state_is_healthy_and_page_renders_32_permanent_teeth(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();

        $response = $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'odontograma']))->assertOk();
        $this->assertSame(32, substr_count($response->getContent(), 'data-tooth="'));

        $temporal = $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'odontograma', 'denticion' => 'temporal']))->assertOk();
        $this->assertSame(20, substr_count($temporal->getContent(), 'data-tooth="'));
    }

    public function test_recording_a_finding_creates_the_current_state(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'caries', 'surfaces' => ['O', 'M']])
            ->assertRedirect()->assertSessionHas('success');

        $f = ToothFinding::query()->current()->firstOrFail();
        $this->assertSame(26, $f->tooth_fdi);
        $this->assertSame('permanente', $f->dentition->value);
        $this->assertSame('caries', $f->condition->value);
        $this->assertSame(['M', 'O'], $f->surfaces);
        $this->assertSame($this->doctorMemberId(), $f->author_member_id);
    }

    public function test_changing_a_finding_closes_the_previous_one_and_keeps_history(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'caries', 'surfaces' => ['O']]);
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'restauracion', 'surfaces' => ['O']]);
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'sano']);

        $all = ToothFinding::query()->where('tooth_fdi', 26)->orderBy('recorded_at')->orderBy('id')->get();
        $this->assertCount(3, $all);
        $this->assertSame(1, $all->whereNull('superseded_at')->count());
        $this->assertSame('sano', ToothFinding::query()->current()->where('tooth_fdi', 26)->firstOrFail()->condition->value);
        $this->assertNull(ToothFinding::query()->current()->where('tooth_fdi', 26)->first()->surfaces);
    }

    public function test_identical_state_is_not_duplicated(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($patient, ['tooth_fdi' => 14, 'condition' => 'ausente']);
        $this->setTooth($patient, ['tooth_fdi' => 14, 'condition' => 'ausente'])->assertSessionHas('info');

        $this->assertSame(1, ToothFinding::query()->where('tooth_fdi', 14)->count());
    }

    public function test_invalid_fdi_numbers_are_rejected(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        foreach ([0, 9, 19, 29, 49, 56, 66, 76, 86, 99, 'abc'] as $tooth) {
            $this->setTooth($patient, ['tooth_fdi' => $tooth, 'condition' => 'caries'])->assertSessionHasErrors('tooth_fdi');
        }
        $this->assertSame(0, ToothFinding::query()->count());
    }

    public function test_temporal_teeth_are_valid_and_dentition_must_match(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($patient, ['tooth_fdi' => 55, 'condition' => 'caries', 'surfaces' => ['O']])->assertSessionHasNoErrors();
        $this->assertSame('temporal', ToothFinding::query()->where('tooth_fdi', 55)->firstOrFail()->dentition->value);

        $this->setTooth($patient, ['tooth_fdi' => 55, 'dentition' => 'permanente', 'condition' => 'caries'])->assertSessionHasErrors('tooth_fdi');
        $this->setTooth($patient, ['tooth_fdi' => 11, 'dentition' => 'temporal', 'condition' => 'caries'])->assertSessionHasErrors('tooth_fdi');
    }

    public function test_surface_and_condition_validation(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'invalida'])->assertSessionHasErrors('condition');
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'caries', 'surfaces' => ['X']])->assertSessionHasErrors('surfaces.0');
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'caries', 'surfaces' => ['O', 'O']])->assertSessionHasErrors();
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'sano', 'surfaces' => ['O']])->assertSessionHasErrors('surfaces');
        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'ausente', 'surfaces' => ['V']])->assertSessionHasErrors('surfaces');
        $this->setTooth($patient, ['condition' => 'caries'])->assertSessionHasErrors('tooth_fdi');

        $this->assertSame(0, ToothFinding::query()->count());
    }

    public function test_history_panel_lists_changes_with_author(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->setTooth($patient, ['tooth_fdi' => 36, 'condition' => 'endodoncia']);

        $this->get(route('expediente.show', ['patient' => $patient->id, 'tab' => 'odontograma']))
            ->assertOk()->assertSee('Endodoncia en 36')->assertSee($this->doctorUser()->full_name);
    }

    public function test_receptionist_cannot_edit_odontogram(): void
    {
        $patient = $this->makePatient();
        $this->actingAsReceptionist();

        $this->setTooth($patient, ['tooth_fdi' => 26, 'condition' => 'caries'])->assertForbidden();
    }

    public function test_findings_of_each_patient_are_independent(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $this->actingAsDoctor();

        $this->setTooth($a, ['tooth_fdi' => 26, 'condition' => 'caries']);
        $this->setTooth($b, ['tooth_fdi' => 26, 'condition' => 'ausente']);

        $this->assertSame('caries', ToothFinding::query()->current()->where('patient_id', $a->id)->firstOrFail()->condition->value);
        $this->assertSame('ausente', ToothFinding::query()->current()->where('patient_id', $b->id)->firstOrFail()->condition->value);
    }
}

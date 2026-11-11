<?php

namespace Tests\Feature\Pacientes;

use App\Models\MedicalRecord;
use App\Models\Patient;

/** Baja lógica, reactivación y anonimización. */
class PatientLifecycleTest extends PacientesTestCase
{
    public function test_destroy_is_a_soft_delete_that_keeps_the_record(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->delete(route('pacientes.destroy', $p))->assertRedirect(route('pacientes.index'))->assertSessionHas('success');

        $this->assertNull(Patient::find($p->id), 'ya no aparece en consultas normales');
        $row = Patient::withTrashed()->findOrFail($p->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('inactive', $row->status->value);
        $this->assertNotNull(MedicalRecord::where('patient_id', $p->id)->first(), 'el expediente se conserva');

        // Fuera del listado normal, visible en "Dados de baja".
        $this->get(route('pacientes.index'))->assertDontSee($p->full_name);
        $this->get(route('pacientes.index', ['estado' => 'deleted']))->assertSee($p->full_name)->assertSee('Baja');
    }

    public function test_deleted_patient_page_still_opens_and_can_be_restored(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->delete(route('pacientes.destroy', $p));

        $this->get(route('pacientes.show', $p))->assertOk()->assertSee('Paciente dado de baja');
        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active']))->assertNotFound();

        $this->post(route('pacientes.restore', $p))->assertRedirect(route('pacientes.show', $p));
        $row = Patient::findOrFail($p->id);
        $this->assertNull($row->deleted_at);
        $this->assertSame('active', $row->status->value);
    }

    public function test_destroy_is_blocked_with_pending_appointments(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->makeAppointment($p, 'confirmada');

        $this->delete(route('pacientes.destroy', $p))->assertSessionHas('error');
        $this->assertNotNull(Patient::find($p->id));
    }

    public function test_destroy_allowed_when_only_past_or_cancelled_appointments(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->makeAppointment($p, 'completada', '-5 days');
        $this->makeAppointment($p, 'cancelada', '+3 days');

        $this->delete(route('pacientes.destroy', $p))->assertSessionHas('success');
        $this->assertNull(Patient::find($p->id));
    }

    public function test_destroy_is_blocked_with_pending_balance(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->makeInvoice($p, '2500.00');

        $this->delete(route('pacientes.destroy', $p))->assertSessionHas('error');
        $this->assertNotNull(Patient::find($p->id));
    }

    // ------------------------------------------------------ anonimización

    public function test_only_admin_can_anonymize(): void
    {
        $p = $this->makePatient();

        $this->actingAsDoctor();
        $this->post(route('pacientes.anonymize', $p), ['confirm_folio' => $p->folio])->assertForbidden();
    }

    public function test_anonymize_requires_the_folio_as_confirmation(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->post(route('pacientes.anonymize', $p), ['confirm_folio' => 'PAC-9999'])->assertSessionHasErrorsIn('anonymize', 'confirm_folio');
        $this->post(route('pacientes.anonymize', $p), [])->assertSessionHasErrorsIn('anonymize', 'confirm_folio');
        $this->assertNull($p->fresh()->anonymized_at);
    }

    public function test_anonymize_removes_identity_but_keeps_clinical_record(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(
            ['birth_date' => now()->subYears(8)->toDateString()],
            ['rfc' => 'LOPO930615AB3', 'legal_name' => 'María López', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600'],
            ['full_name' => 'Tutora Prueba', 'phone' => '5511112222'],
        );
        $p->medicalRecord->update(['allergies' => 'Látex']);

        $this->post(route('pacientes.anonymize', $p), ['confirm_folio' => $p->folio])->assertSessionHas('success');

        $row = Patient::withTrashed()->findOrFail($p->id);
        $this->assertNotNull($row->anonymized_at);
        $this->assertNull($row->phone);
        $this->assertNull($row->email);
        $this->assertNull($row->birth_date);
        $this->assertStringNotContainsString('López', $row->full_name);
        $this->assertStringContainsString($p->folio, $row->full_name);
        $this->assertSame(0, $row->fiscalProfiles()->count());
        $this->assertSame(0, $row->guardians()->count());
        $this->assertSame('Látex', MedicalRecord::where('patient_id', $p->id)->value('allergies'), 'el dato clínico se conserva');
    }

    public function test_anonymized_patient_is_read_only(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->post(route('pacientes.anonymize', $p), ['confirm_folio' => $p->folio]);

        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active']))->assertSessionHas('error');
        $this->post(route('pacientes.restore', $p))->assertSessionHas('error');
        $this->post(route('pacientes.fiscal.store', $p), ['rfc' => 'LOPO930615AB3', 'legal_name' => 'X Y', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600'])
            ->assertSessionHas('error');
        $this->assertSame(0, $p->fiscalProfiles()->count());
        $this->get(route('pacientes.show', $p))->assertOk()->assertSee('Paciente anonimizado');
    }

    public function test_anonymize_is_blocked_with_pending_appointments(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->makeAppointment($p);

        $this->post(route('pacientes.anonymize', $p), ['confirm_folio' => $p->folio])->assertSessionHas('error');
        $this->assertNull($p->fresh()->anonymized_at);
    }
}

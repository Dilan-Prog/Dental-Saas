<?php

namespace Tests\Feature\Pacientes;

use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

class PatientCrudTest extends PacientesTestCase
{
    // ---------------------------------------------------------- acceso

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('pacientes.index'))->assertRedirect(route('login'));
        $this->post(route('pacientes.store'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_user_without_permission_gets_403(): void
    {
        $user = $this->createClinicUser([], 'sin_permisos');
        $patient = $this->makePatient();

        $this->actingAs($user)->get(route('pacientes.index'))->assertForbidden();
        $this->actingAs($user)->get(route('pacientes.show', $patient))->assertForbidden();
        $this->actingAs($user)->post(route('pacientes.store'), $this->payload())->assertForbidden();
    }

    public function test_receptionist_can_view_and_create_but_not_export_or_delete(): void
    {
        $this->actingAsReceptionist();
        $patient = $this->makePatient();

        $this->get(route('pacientes.index'))->assertOk();
        $this->post(route('pacientes.store'), $this->payload(['phone' => '5599887766', 'email' => 'otro@correo.mx', 'full_name' => 'Pedro Pérez Gómez']))
            ->assertRedirect();
        $this->get(route('pacientes.export'))->assertForbidden();
        $this->delete(route('pacientes.destroy', $patient))->assertForbidden();
    }

    // ---------------------------------------------------------- listado

    public function test_index_lists_patients_with_search_and_filters(): void
    {
        $this->actingAsAdmin();
        $doctor = $this->doctorMemberId();
        $a = $this->makePatient(['full_name' => 'Renata Aguilar Vega', 'phone' => '5599043367', 'email' => 'renata@x.mx', 'assigned_doctor_member_id' => $doctor]);
        $b = $this->makePatient(['full_name' => 'Sergio Naranjo Téllez', 'phone' => '5522456690', 'email' => 'sergio@x.mx']);
        $b->forceFill(['status' => 'inactive'])->save();

        $this->get(route('pacientes.index'))->assertOk()->assertSee('Renata Aguilar Vega')->assertSee('Sergio Naranjo Téllez');
        $this->get(route('pacientes.index', ['q' => 'renata']))->assertSee('Renata Aguilar Vega')->assertDontSee('Sergio Naranjo');
        $this->get(route('pacientes.index', ['q' => $b->folio]))->assertSee('Sergio Naranjo')->assertDontSee('Renata Aguilar');
        $this->get(route('pacientes.index', ['q' => '55 2245 6690']))->assertSee('Sergio Naranjo')->assertDontSee('Renata Aguilar');
        $this->get(route('pacientes.index', ['q' => 'sergio@x.mx']))->assertSee('Sergio Naranjo');
        $this->get(route('pacientes.index', ['doctor' => $doctor]))->assertSee('Renata Aguilar')->assertDontSee('Sergio Naranjo');
        $this->get(route('pacientes.index', ['estado' => 'inactive']))->assertSee('Sergio Naranjo')->assertDontSee('Renata Aguilar');
        $this->get(route('pacientes.index', ['estado' => 'active']))->assertSee('Renata Aguilar')->assertDontSee('Sergio Naranjo');
        $this->get(route('pacientes.index', ['q' => 'zzzz-no-existe']))->assertOk()->assertSee('Sin resultados');
    }

    public function test_index_shows_balance_and_last_visit_calculated_from_data(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(['full_name' => 'Paciente Con Saldo']);
        $this->makeInvoice($p, '4100.00');
        $this->makeInvoice($p, '900.00', 'borrador'); // los borradores no cuentan
        $this->makeAppointment($p, 'completada', '-3 days');

        $this->get(route('pacientes.index'))->assertOk()->assertSee('$4,100.00')->assertDontSee('$5,000.00');
    }

    public function test_index_paginates_ten_per_page(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 12) as $i) {
            $this->makePatient(['full_name' => 'Paciente Número '.$this->words($i), 'phone' => '55100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => "p{$i}@x.mx"]);
        }

        $this->get(route('pacientes.index'))->assertOk()->assertSee('Mostrando 1-10 de 12');
        $this->get(route('pacientes.index', ['page' => 2]))->assertSee('Mostrando 11-12 de 12');
    }

    // ------------------------------------------------------------- alta

    public function test_store_creates_patient_with_folio_and_medical_record(): void
    {
        $this->actingAsAdmin();
        DB::table('folio_sequences')->where('document_type', 'patient')->update(['last_value' => 480]);

        $this->post(route('pacientes.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $patient = Patient::firstOrFail();
        $this->assertSame('PAC-0481', $patient->folio);
        $this->assertSame('5512345678', $patient->phone);               // normalizado a 10 dígitos
        $this->assertSame('active', $patient->status->value);
        $this->assertSame($this->organizationId(), $patient->organization_id);
        $this->assertNotNull(MedicalRecord::where('patient_id', $patient->id)->first(), 'debe crear el expediente');
        $this->assertSame(0, \App\Models\PatientFiscalProfile::count());
    }

    public function test_store_with_fiscal_data_creates_default_profile(): void
    {
        $this->actingAsAdmin();

        $this->post(route('pacientes.store'), $this->payload(['fiscal' => [
            'rfc' => 'lopo930615ab3', 'legal_name' => 'María Fernanda López Ortega', 'tax_regime_code' => '612',
            'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'D01',
        ]]))->assertSessionHasNoErrors();

        $profile = Patient::firstOrFail()->fiscalProfiles()->firstOrFail();
        $this->assertSame('LOPO930615AB3', $profile->rfc);               // mayúsculas
        $this->assertTrue($profile->is_default);
    }

    public function test_store_requires_guardian_for_minors_and_saves_it(): void
    {
        $this->actingAsAdmin();
        $minor = $this->payload(['birth_date' => now()->subYears(9)->toDateString(), 'full_name' => 'Sofía Lozano Bernal']);

        $this->post(route('pacientes.store'), $minor)
            ->assertSessionHasErrorsIn('patientNew', ['guardian.full_name', 'guardian.phone']);
        $this->assertSame(0, Patient::count());

        $this->post(route('pacientes.store'), $minor + ['guardian' => ['full_name' => 'Mariana Bernal', 'relationship' => 'Madre', 'phone' => '55 6000 1123']])
            ->assertSessionHasNoErrors();

        $patient = Patient::firstOrFail();
        $this->assertSame('Mariana Bernal', $patient->guardians()->firstOrFail()->full_name);
        $this->assertTrue($patient->guardians()->first()->is_primary);
    }

    public function test_adult_does_not_need_guardian(): void
    {
        $this->actingAsAdmin();
        $this->post(route('pacientes.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Models\PatientGuardian::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function test_store_rejects_invalid_data(array $override, string $errorKey): void
    {
        $this->actingAsAdmin();

        $this->post(route('pacientes.store'), $this->payload($override))->assertSessionHasErrorsIn('patientNew', $errorKey);
        $this->assertSame(0, Patient::count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'sin nombre' => [['full_name' => ''], 'full_name'],
            'nombre con números' => [['full_name' => 'Juan 123'], 'full_name'],
            'nombre muy corto' => [['full_name' => 'Al'], 'full_name'],
            'sin fecha de nacimiento' => [['birth_date' => ''], 'birth_date'],
            'fecha futura' => [['birth_date' => '2999-01-01'], 'birth_date'],
            'fecha inválida' => [['birth_date' => '31/02/2020'], 'birth_date'],
            'fecha anterior a 1900' => [['birth_date' => '1899-12-31'], 'birth_date'],
            'sin teléfono' => [['phone' => ''], 'phone'],
            'teléfono corto' => [['phone' => '55 1234'], 'phone'],
            'teléfono con letras' => [['phone' => 'abcdefghij'], 'phone'],
            'correo inválido' => [['email' => 'no-es-correo'], 'email'],
            'sexo inválido' => [['sex' => 'robot'], 'sex'],
            'doctor inexistente' => [['assigned_doctor_member_id' => '00000000-0000-4000-8000-000000000000'], 'assigned_doctor_member_id'],
            'rfc inválido' => [['fiscal' => ['rfc' => 'ABC123', 'legal_name' => 'X Y', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600']], 'fiscal.rfc'],
            'fiscal incompleto' => [['fiscal' => ['rfc' => 'LOPO930615AB3']], 'fiscal.legal_name'],
            'régimen fuera de catálogo' => [['fiscal' => ['rfc' => 'LOPO930615AB3', 'legal_name' => 'X Y', 'tax_regime_code' => '999', 'fiscal_postal_code' => '06600']], 'fiscal.tax_regime_code'],
            'régimen de moral con rfc físico' => [['fiscal' => ['rfc' => 'LOPO930615AB3', 'legal_name' => 'X Y', 'tax_regime_code' => '601', 'fiscal_postal_code' => '06600']], 'fiscal.tax_regime_code'],
            'rfc genérico sin 616' => [['fiscal' => ['rfc' => 'XAXX010101000', 'legal_name' => 'PUBLICO EN GENERAL', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600']], 'fiscal.tax_regime_code'],
            'cp inválido' => [['fiscal' => ['rfc' => 'LOPO930615AB3', 'legal_name' => 'X Y', 'tax_regime_code' => '612', 'fiscal_postal_code' => '123']], 'fiscal.fiscal_postal_code'],
            'uso cfdi inválido' => [['fiscal' => ['rfc' => 'LOPO930615AB3', 'legal_name' => 'X Y', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'ZZ9']], 'fiscal.default_cfdi_use'],
        ];
    }

    public function test_assigned_doctor_must_have_doctor_role(): void
    {
        $this->actingAsAdmin();
        $receptionistMember = $this->memberId($this->userByEmail('recepcion@dental.test'));

        $this->post(route('pacientes.store'), $this->payload(['assigned_doctor_member_id' => $receptionistMember]))
            ->assertSessionHasErrorsIn('patientNew', 'assigned_doctor_member_id');

        $this->post(route('pacientes.store'), $this->payload(['assigned_doctor_member_id' => $this->doctorMemberId()]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->doctorMemberId(), Patient::firstOrFail()->assigned_doctor_member_id);
    }

    public function test_duplicate_warning_can_be_overridden(): void
    {
        $this->actingAsAdmin();
        $this->makePatient();

        // Mismo teléfono => advertencia, no se crea.
        $this->post(route('pacientes.store'), $this->payload(['full_name' => 'Otra Persona Distinta', 'email' => 'otra@x.mx']))
            ->assertSessionHasErrorsIn('patientNew', 'duplicate');
        $this->assertSame(1, Patient::count());

        // Mismo nombre y fecha de nacimiento (con otro teléfono) también.
        $this->post(route('pacientes.store'), $this->payload(['phone' => '5500000001', 'email' => 'x@x.mx']))
            ->assertSessionHasErrorsIn('patientNew', 'duplicate');

        // Confirmando, se registra.
        $this->post(route('pacientes.store'), $this->payload(['full_name' => 'Otra Persona Distinta', 'email' => 'otra@x.mx', 'ignore_duplicates' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Patient::count());
    }

    public function test_folios_are_consecutive(): void
    {
        $this->actingAsAdmin();
        $a = $this->makePatient(['phone' => '5500000010', 'email' => 'a@x.mx', 'full_name' => 'Ana Uno']);
        $b = $this->makePatient(['phone' => '5500000011', 'email' => 'b@x.mx', 'full_name' => 'Beto Dos']);

        $this->assertSame((int) substr($a->folio, 4) + 1, (int) substr($b->folio, 4));
        $this->assertStringStartsWith('PAC-', $a->folio);
    }

    // ----------------------------------------------------------- ficha

    public function test_show_renders_patient_details(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(['assigned_doctor_member_id' => $this->doctorMemberId()],
            ['rfc' => 'LOPO930615AB3', 'legal_name' => 'María López', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'D01']);
        $p->medicalRecord->update(['allergies' => 'Penicilina']);
        $this->makeAppointment($p);

        $this->get(route('pacientes.show', $p))
            ->assertOk()
            ->assertSee($p->full_name)->assertSee($p->folio)->assertSee('55 1234 5678')
            ->assertSee('LOPO930615AB3')->assertSee('Penicilina')->assertSee('Próximas citas');
    }

    public function test_show_unknown_patient_is_404(): void
    {
        $this->actingAsAdmin();
        $this->get(route('pacientes.show', '00000000-0000-4000-8000-000000000000'))->assertNotFound();
    }

    // ------------------------------------------------------------ edición

    public function test_update_changes_patient_data(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->put(route('pacientes.update', $p), $this->payload([
            'full_name' => 'María Fernanda López Ortega de Ruiz', 'phone' => '(55) 9999-0000', 'status' => 'inactive',
            'assigned_doctor_member_id' => $this->doctorMemberId(),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('pacientes.show', $p));

        $p->refresh();
        $this->assertSame('María Fernanda López Ortega de Ruiz', $p->full_name);
        $this->assertSame('5599990000', $p->phone);
        $this->assertSame('inactive', $p->status->value);
        $this->assertSame('PAC-'.substr($p->folio, 4), $p->folio, 'el folio no cambia');
    }

    public function test_update_validates_and_keeps_self_out_of_duplicates(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        // Guardar sin cambiar teléfono/correo no debe marcarse como duplicado de sí mismo.
        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active']))->assertSessionHasNoErrors();

        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active', 'phone' => '123']))
            ->assertSessionHasErrorsIn('patientEdit', 'phone');
        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'x']))
            ->assertSessionHasErrorsIn('patientEdit', 'status');
        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active', 'birth_date' => '2999-01-01']))
            ->assertSessionHasErrorsIn('patientEdit', 'birth_date');
    }

    public function test_update_to_minor_requires_guardian_when_none_exists(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active', 'birth_date' => now()->subYears(10)->toDateString()]))
            ->assertSessionHasErrorsIn('patientEdit', 'guardian.full_name');

        $this->put(route('pacientes.update', $p), $this->payload(['status' => 'active', 'birth_date' => now()->subYears(10)->toDateString(),
            'guardian' => ['full_name' => 'Tutor Prueba', 'email' => 'tutor@x.mx']]))->assertSessionHasNoErrors();
        $this->assertSame(1, $p->guardians()->count());
    }

    // ----------------------------------------------------------- exportar

    public function test_export_streams_csv_without_formula_injection(): void
    {
        $this->actingAsAdmin();
        $this->makePatient(['full_name' => 'Ana Prueba', 'phone' => '5500000020', 'email' => 'ana@x.mx']);
        $evil = $this->makePatient(['full_name' => 'Zeta Maliciosa', 'phone' => '5500000021', 'email' => 'z@x.mx']);
        $evil->forceFill(['full_name' => '=HYPERLINK("http://x")'])->save();

        $response = $this->get(route('pacientes.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Folio,Nombre,Edad', $csv);
        $this->assertStringContainsString('Ana Prueba', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'las fórmulas deben neutralizarse');
    }

    private function words(int $n): string
    {
        return ['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete', 'Ocho', 'Nueve', 'Diez', 'Once', 'Doce'][$n - 1];
    }
}

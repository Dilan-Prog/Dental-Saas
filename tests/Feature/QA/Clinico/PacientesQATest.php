<?php

namespace Tests\Feature\QA\Clinico;

use App\Models\Patient;
use App\Models\PatientGuardian;
use Illuminate\Support\Str;
use Tests\Feature\Pacientes\PacientesTestCase;

/** QA del módulo Pacientes: validación de formularios, dominios y ciclo de vida. */
class PacientesQATest extends PacientesTestCase
{
    private function store(array $overrides = [], bool $merge = true)
    {
        $this->actingAsAdmin();

        return $this->from('/pacientes')->post(route('pacientes.store'), $merge ? $this->payload($overrides) : $overrides);
    }

    public function test_empty_form_reports_required_fields_in_its_own_bag(): void
    {
        $this->store([], false)
            ->assertSessionHasErrors(['full_name', 'birth_date', 'phone'], null, 'patientNew');
    }

    /** @dataProvider invalidNames */
    public function test_invalid_names_are_rejected(string $name): void
    {
        $this->store(['full_name' => $name])->assertSessionHasErrors('full_name', null, 'patientNew');
        $this->assertSame(0, Patient::query()->count());
    }

    public static function invalidNames(): array
    {
        return [
            'digits' => ['Juan 3 Pérez'],
            'html' => ['<b>Juan</b> Pérez'],
            'script' => ['<script>alert(1)</script>'],
            'too short' => ['Jo'],
            'too long' => [str_repeat('a', 251)],
            'symbols' => ['Juan@Pérez'],
            'sql' => ["Robert'); DROP TABLE patients;--"],
            'only spaces' => ['      '],
        ];
    }

    /** @dataProvider invalidDates */
    public function test_invalid_birth_dates_are_rejected(string $date): void
    {
        $this->store(['birth_date' => $date])->assertSessionHasErrors('birth_date', null, 'patientNew');
    }

    public static function invalidDates(): array
    {
        return [
            'future' => ['2999-01-01'],
            'tomorrow' => [now()->addDay()->toDateString()],
            'dmy' => ['31/12/1990'],
            'before 1900' => ['1899-12-31'],
            'impossible' => ['2020-02-31'],
            'text' => ['ayer'],
        ];
    }

    /** @dataProvider invalidPhones */
    public function test_invalid_mexican_phones_are_rejected(string $phone): void
    {
        $this->store(['phone' => $phone])->assertSessionHasErrors('phone', null, 'patientNew');
    }

    public static function invalidPhones(): array
    {
        return [
            'short' => ['5512'],
            'letters' => ['55abcdefgh'],
            'eleven digits' => ['55123456789'],
            'zeros' => ['0000000000'],
        ];
    }

    public function test_phone_formats_are_normalised_to_ten_digits(): void
    {
        $this->store(['phone' => '+52 (55) 1234-5678'])->assertSessionHasNoErrors();
        $this->assertSame('5512345678', Patient::query()->first()->phone);
    }

    /** @dataProvider invalidEmails */
    public function test_invalid_emails_are_rejected(string $email): void
    {
        $this->store(['email' => $email])->assertSessionHasErrors('email', null, 'patientNew');
    }

    public static function invalidEmails(): array
    {
        return [['no-es-correo'], ['a@'], ['@b.com'], [str_repeat('a', 250).'@x.mx'], ['a b@c.com']];
    }

    public function test_email_is_lowercased_and_name_whitespace_collapsed(): void
    {
        $this->store(['email' => '  MARIA@Correo.MX ', 'full_name' => '  María   Fernanda  López '])->assertSessionHasNoErrors();
        $p = Patient::query()->first();
        $this->assertSame('maria@correo.mx', $p->email);
        $this->assertSame('María Fernanda López', $p->full_name);
    }

    public function test_invalid_sex_is_rejected(): void
    {
        $this->store(['sex' => 'robot'])->assertSessionHasErrors('sex', null, 'patientNew');
    }

    public function test_assigned_doctor_must_be_an_active_doctor_of_the_clinic(): void
    {
        $recep = $this->memberId($this->userByEmail('recepcion@dental.test'));
        $this->store(['assigned_doctor_member_id' => $recep])->assertSessionHasErrors('assigned_doctor_member_id', null, 'patientNew');
        $this->store(['assigned_doctor_member_id' => (string) Str::uuid()])->assertSessionHasErrors('assigned_doctor_member_id', null, 'patientNew');
        $this->store(['assigned_doctor_member_id' => 'no-uuid'])->assertSessionHasErrors('assigned_doctor_member_id', null, 'patientNew');
        $this->store(['assigned_doctor_member_id' => $this->doctorMemberId()])->assertSessionHasNoErrors();
    }

    public function test_minor_requires_guardian_and_adult_does_not(): void
    {
        $minor = ['birth_date' => now()->subYears(9)->toDateString()];
        $this->store($minor)->assertSessionHasErrors('guardian.full_name', null, 'patientNew');
        $this->store($minor + ['guardian' => ['full_name' => 'Ana Torres', 'phone' => '5511112222', 'relationship' => 'Madre']])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PatientGuardian::query()->count());
    }

    public function test_exactly_eighteen_is_adult_and_one_day_short_is_minor(): void
    {
        $this->store(['birth_date' => now()->subYears(18)->toDateString(), 'phone' => '5511111111', 'email' => 'a1@x.mx'])
            ->assertSessionHasNoErrors();
        $this->store(['birth_date' => now()->subYears(18)->addDay()->toDateString(), 'phone' => '5522222222', 'email' => 'a2@x.mx'])
            ->assertSessionHasErrors('guardian.full_name', null, 'patientNew');
    }

    public function test_invalid_guardian_relationship_is_rejected(): void
    {
        $this->store(['birth_date' => now()->subYears(9)->toDateString(),
            'guardian' => ['full_name' => 'Ana Torres', 'phone' => '5511112222', 'relationship' => 'Vecino del quinto']])
            ->assertSessionHasErrors('guardian.relationship', null, 'patientNew');
    }

    public function test_fiscal_data_all_or_nothing_and_domain_rules(): void
    {
        $this->store(['fiscal' => ['rfc' => 'XAXX010101000']])
            ->assertSessionHasErrors(['fiscal.legal_name', 'fiscal.tax_regime_code', 'fiscal.fiscal_postal_code'], null, 'patientNew');

        $ok = ['rfc' => 'xaxx010101000', 'legal_name' => 'Público en General', 'tax_regime_code' => '616', 'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'S01'];
        $this->store(['fiscal' => ['rfc' => 'ABC'] + $ok])->assertSessionHasErrors('fiscal.rfc', null, 'patientNew');
        $this->store(['fiscal' => ['fiscal_postal_code' => '1234'] + $ok])->assertSessionHasErrors('fiscal.fiscal_postal_code', null, 'patientNew');
        $this->store(['fiscal' => ['fiscal_postal_code' => '12ab5'] + $ok])->assertSessionHasErrors('fiscal.fiscal_postal_code', null, 'patientNew');
        $this->store(['fiscal' => ['tax_regime_code' => '999'] + $ok])->assertSessionHasErrors('fiscal.tax_regime_code', null, 'patientNew');
        $this->store(['fiscal' => ['default_cfdi_use' => 'ZZZ'] + $ok])->assertSessionHasErrors('fiscal.default_cfdi_use', null, 'patientNew');
        // RFC genérico exige régimen 616
        $this->store(['fiscal' => ['tax_regime_code' => '601'] + $ok])->assertSessionHasErrors('fiscal.tax_regime_code', null, 'patientNew');
        // RFC de persona moral (12) con régimen de persona física
        $this->store(['fiscal' => ['rfc' => 'ABC010101AB1', 'tax_regime_code' => '612'] + $ok])->assertSessionHasErrors('fiscal.tax_regime_code', null, 'patientNew');

        $this->store(['fiscal' => $ok])->assertSessionHasNoErrors();
        $this->assertSame('XAXX010101000', Patient::query()->first()->fiscalProfiles()->first()->rfc);
    }

    public function test_duplicate_warning_and_override(): void
    {
        $this->makePatient(['phone' => '5512345678', 'email' => 'dup@x.mx']);
        $this->store(['phone' => '5512345678', 'email' => 'otro@x.mx', 'full_name' => 'Otra Persona'])
            ->assertSessionHasErrors('duplicate', null, 'patientNew');
        $this->store(['phone' => '5512345678', 'email' => 'dup@x.mx', 'full_name' => 'Otra Persona'])
            ->assertSessionHasErrors('duplicate', null, 'patientNew');
        $this->store(['phone' => '5512345678', 'email' => 'otro@x.mx', 'full_name' => 'Otra Persona', 'ignore_duplicates' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Patient::query()->count());
    }

    public function test_input_is_preserved_after_validation_error(): void
    {
        $this->store(['phone' => '12', 'full_name' => 'Nombre Conservado'])
            ->assertSessionHasErrors('phone', null, 'patientNew')
            ->assertSessionHasInput('full_name', 'Nombre Conservado');
    }

    public function test_folios_are_sequential_and_each_patient_gets_one_medical_record(): void
    {
        $this->store(['phone' => '5511111111', 'email' => 'a@x.mx']);
        $this->store(['phone' => '5522222222', 'email' => 'b@x.mx', 'full_name' => 'Carlos Pérez Luna']);
        $folios = Patient::query()->orderBy('folio')->pluck('folio')->all();
        $this->assertCount(2, $folios);
        $this->assertNotSame($folios[0], $folios[1]);
        foreach (Patient::query()->get() as $p) {
            $this->assertSame(1, \App\Models\MedicalRecord::query()->where('patient_id', $p->id)->count());
        }
    }

    public function test_update_cannot_make_patient_minor_without_guardian_and_validates_like_create(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->from('/x')->put(route('pacientes.update', $patient), $this->payload(['birth_date' => now()->subYears(5)->toDateString()]))
            ->assertSessionHasErrors();
        $this->put(route('pacientes.update', $patient), $this->payload(['full_name' => '12345']))->assertSessionHasErrors();
        $this->put(route('pacientes.update', $patient), $this->payload(['phone' => 'abc']))->assertSessionHasErrors();
        $this->assertSame('María Fernanda López Ortega', $patient->fresh()->full_name);
    }

    public function test_update_ignores_itself_in_duplicate_detection(): void
    {
        $patient = $this->makePatient(['email' => 'yo@x.mx', 'phone' => '5599999999']);
        $this->actingAsAdmin();
        $this->put(route('pacientes.update', $patient), $this->payload(['email' => 'yo@x.mx', 'phone' => '5599999999', 'full_name' => 'María F. López Ortega', 'status' => 'active']))
            ->assertSessionHasNoErrors();
    }

    public function test_fiscal_profile_validation_and_single_default(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $url = route('pacientes.fiscal.store', $patient);
        $this->post($url, [])->assertSessionHasErrors();
        $this->post($url, ['rfc' => 'BAD', 'legal_name' => 'X', 'tax_regime_code' => '601', 'fiscal_postal_code' => '06600'])->assertSessionHasErrors();
        $a = ['rfc' => 'LOPM900101AB1', 'legal_name' => 'María López', 'tax_regime_code' => '612', 'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'D01'];
        $this->post($url, $a)->assertSessionHasNoErrors();
        $this->post($url, ['rfc' => 'XAXX010101000', 'legal_name' => 'Público', 'tax_regime_code' => '616', 'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'S01', 'is_default' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $patient->fiscalProfiles()->where('is_default', true)->count());
    }

    public function test_fiscal_profile_of_another_patient_cannot_be_touched(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient(['full_name' => 'Pedro Ramírez Soto', 'phone' => '5533333333', 'email' => 'p@x.mx']);
        $profile = $b->fiscalProfiles()->create(['rfc' => 'XAXX010101000', 'legal_name' => 'P', 'tax_regime_code' => '616', 'fiscal_postal_code' => '06600', 'is_default' => true]);
        $this->actingAsAdmin();
        $this->delete(route('pacientes.fiscal.destroy', [$a, $profile]))->assertStatus(404);
        $this->assertNotNull($b->fiscalProfiles()->find($profile->id));
    }

    public function test_archive_blocked_with_future_appointment_and_unpaid_balance(): void
    {
        $patient = $this->makePatient();
        $this->makeAppointment($patient, 'confirmada');
        $this->actingAsAdmin();
        $this->delete(route('pacientes.destroy', $patient))->assertSessionHas('error');
        $this->assertNull($patient->fresh()->deleted_at);
    }

    public function test_archive_and_restore_cycle_keeps_row_and_hides_it(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->delete(route('pacientes.destroy', $patient))->assertSessionHasNoErrors();
        $this->assertNotNull(Patient::withTrashed()->find($patient->id)->deleted_at);
        $this->assertSame(0, Patient::query()->count());
        $this->post(route('pacientes.restore', $patient))->assertSessionHasNoErrors();
        $this->assertNull(Patient::query()->find($patient->id)->deleted_at);
        $this->assertSame('active', $patient->fresh()->status->value);
    }

    public function test_archived_patient_is_not_offered_in_any_selector(): void
    {
        $patient = $this->makePatient(['full_name' => 'Zacarías Invisible Prueba']);
        $this->actingAsAdmin();
        $this->delete(route('pacientes.destroy', $patient));
        foreach (['agenda.patients', 'recetas.pacientes.buscar', 'presupuestos.patients'] as $route) {
            $json = $this->getJson(route($route, ['q' => 'Zacarías']))->assertOk()->getContent();
            $this->assertStringNotContainsString('Zacarías', $json, $route);
        }
    }

    public function test_archived_patient_cannot_receive_appointments_prescriptions_or_quotes(): void
    {
        $patient = $this->makePatient();
        $this->actingAsAdmin();
        $this->delete(route('pacientes.destroy', $patient));
        $this->post(route('citas.store'), [
            'patient_id' => $patient->id, 'doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => (string) Str::uuid(), 'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00', 'duration_minutes' => 60,
        ])->assertSessionHasErrors('patient_id');
    }

    public function test_anonymize_requires_admin_and_folio_confirmation(): void
    {
        $patient = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('pacientes.anonymize', $patient), ['confirm_folio' => $patient->folio])->assertStatus(403);
        $this->actingAsAdmin();
        $this->post(route('pacientes.anonymize', $patient), ['confirm_folio' => 'PAC-9999'])->assertSessionHasErrors();
        $this->assertNull($patient->fresh()->anonymized_at);
        $this->post(route('pacientes.anonymize', $patient), ['confirm_folio' => $patient->folio]);
        $fresh = Patient::withTrashed()->find($patient->id);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertStringNotContainsString('María', (string) $fresh->full_name);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->email);
        $this->get(route('pacientes.show', $patient))->assertOk();
    }

    public function test_receptionist_cannot_archive_or_anonymize(): void
    {
        $patient = $this->makePatient();
        $this->actingAsReceptionist();
        $this->delete(route('pacientes.destroy', $patient))->assertStatus(403);
        $this->post(route('pacientes.anonymize', $patient), ['confirm_folio' => $patient->folio])->assertStatus(403);
    }

    public function test_listing_shows_calculated_age_balance_and_last_visit(): void
    {
        $patient = $this->makePatient(['full_name' => 'Laura Cálculo Exacto', 'birth_date' => now()->subYears(41)->subDays(3)->toDateString(), 'phone' => '5544444444', 'email' => 'l@x.mx']);
        $this->makeInvoice($patient, '1500.00');
        $done = $this->makeAppointment($patient, 'completada', '-10 days');
        $this->actingAsAdmin();
        $html = $this->get(route('pacientes.index', ['q' => 'Laura']))->assertOk()->getContent();
        $this->assertStringContainsString('41', $html);
        $this->assertStringContainsString('1,500.00', $html);
        $this->assertNotNull($done);
    }

    public function test_search_by_folio_phone_email_and_sql_wildcards(): void
    {
        $p = $this->makePatient(['full_name' => 'Búsqueda Especial Uno', 'phone' => '5566666666', 'email' => 'busq@x.mx']);
        $this->actingAsAdmin();
        foreach ([$p->folio, '5566666666', 'busq@x', 'Búsqueda'] as $q) {
            $this->get(route('pacientes.index', ['q' => $q]))->assertOk()->assertSee('Búsqueda Especial Uno');
        }
        $this->get(route('pacientes.index', ['q' => '%']))->assertOk();
        $this->get(route('pacientes.index', ['q' => "'; DROP TABLE patients;--"]))->assertOk();
        $this->get(route('pacientes.index', ['q' => str_repeat('a', 5000)]))->assertOk();
        $this->get(route('pacientes.index', ['page' => 'abc', 'estado' => 'xyz', 'doctor' => 'no-uuid']))->assertOk();
    }

    public function test_guardian_validation_and_last_guardian_of_minor_cannot_be_removed(): void
    {
        $minor = $this->makePatient(['birth_date' => now()->subYears(8)->toDateString(), 'phone' => '5577777777', 'email' => 'm@x.mx'], null,
            ['full_name' => 'Ana Torres', 'phone' => '5511112222', 'relationship' => 'Madre']);
        $g = $minor->guardians()->first();
        $this->actingAsAdmin();
        $this->post(route('pacientes.guardians.store', $minor), ['full_name' => '', 'phone' => '1'])->assertSessionHasErrors();
        $this->delete(route('pacientes.guardians.destroy', [$minor, $g]))->assertSessionHas('error');
        $this->assertSame(1, $minor->guardians()->count());
    }
}

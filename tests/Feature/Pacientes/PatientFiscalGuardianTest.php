<?php

namespace Tests\Feature\Pacientes;

use App\Models\Patient;
use Database\Seeders\DemoPacientesSeeder;

/** Perfiles fiscales, tutores y datos demo. */
class PatientFiscalGuardianTest extends PacientesTestCase
{
    private function fiscal(array $o = []): array
    {
        return array_merge([
            'rfc' => 'LOPO930615AB3', 'legal_name' => 'María López Ortega', 'tax_regime_code' => '612',
            'fiscal_postal_code' => '06600', 'default_cfdi_use' => 'D01',
        ], $o);
    }

    // ------------------------------------------------------------ fiscales

    public function test_first_fiscal_profile_becomes_default_and_only_one_stays_default(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal())->assertSessionHasNoErrors();
        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal(['rfc' => 'ROFI940820KT5', 'is_default' => 1]))->assertSessionHasNoErrors();
        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal(['rfc' => 'AUVR990415AB3']))->assertSessionHasNoErrors();

        $defaults = $p->fiscalProfiles()->where('is_default', true)->get();
        $this->assertCount(1, $defaults);
        $this->assertSame('ROFI940820KT5', $defaults->first()->rfc);
        $this->assertSame(3, $p->fiscalProfiles()->count());
    }

    public function test_fiscal_profile_can_be_edited_and_default_switched_and_deleted(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();
        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal());
        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal(['rfc' => 'ROFI940820KT5']));
        [$first, $second] = $p->fiscalProfiles()->orderBy('rfc')->get()->all();
        $default = $p->fiscalProfiles()->where('is_default', true)->first();

        // Editar
        $this->post(route('pacientes.fiscal.store', $p), $this->fiscal(['profile_id' => $default->id, 'legal_name' => 'Nombre Corregido', 'is_default' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Nombre Corregido', $default->fresh()->legal_name);

        // Cambiar predeterminado
        $other = $p->fiscalProfiles()->where('id', '!=', $default->id)->first();
        $this->post(route('pacientes.fiscal.default', [$p, $other]))->assertSessionHas('success');
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);
        $this->assertSame(1, $p->fiscalProfiles()->where('is_default', true)->count());

        // Eliminar el predeterminado => el otro hereda
        $this->delete(route('pacientes.fiscal.destroy', [$p, $other]))->assertSessionHas('success');
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_fiscal_profile_validation(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $cases = [
            'rfc' => [['rfc' => ''], 'rfc'],
            'rfc malo' => [['rfc' => 'XYZ'], 'rfc'],
            'razón social' => [['legal_name' => ''], 'legal_name'],
            'régimen vacío' => [['tax_regime_code' => ''], 'tax_regime_code'],
            'régimen no existe' => [['tax_regime_code' => '000'], 'tax_regime_code'],
            'régimen moral con rfc de física' => [['tax_regime_code' => '601'], 'tax_regime_code'],
            'cp' => [['fiscal_postal_code' => '99'], 'fiscal_postal_code'],
            'uso cfdi' => [['default_cfdi_use' => 'XX'], 'default_cfdi_use'],
        ];

        foreach ($cases as $label => [$override, $key]) {
            $this->post(route('pacientes.fiscal.store', $p), $this->fiscal($override))
                ->assertSessionHasErrorsIn('fiscal', $key, "caso: {$label}");
        }
        $this->assertSame(0, $p->fiscalProfiles()->count());
    }

    public function test_rfc_must_be_unique_per_patient_but_not_across_patients(): void
    {
        $this->actingAsAdmin();
        $a = $this->makePatient();
        $b = $this->makePatient(['full_name' => 'Otro Paciente Distinto', 'phone' => '5500000030', 'email' => 'o@x.mx']);

        $this->post(route('pacientes.fiscal.store', $a), $this->fiscal())->assertSessionHasNoErrors();
        $this->post(route('pacientes.fiscal.store', $a), $this->fiscal())->assertSessionHasErrorsIn('fiscal', 'rfc');
        $this->post(route('pacientes.fiscal.store', $b), $this->fiscal())->assertSessionHasNoErrors();
    }

    public function test_generic_public_rfc_requires_regime_616(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $generic = $this->fiscal(['rfc' => 'XAXX010101000', 'legal_name' => 'PUBLICO EN GENERAL', 'default_cfdi_use' => 'S01']);
        $this->post(route('pacientes.fiscal.store', $p), $generic)->assertSessionHasErrorsIn('fiscal', 'tax_regime_code');
        $this->post(route('pacientes.fiscal.store', $p), array_merge($generic, ['tax_regime_code' => '616']))->assertSessionHasNoErrors();
    }

    public function test_cannot_touch_a_profile_of_another_patient(): void
    {
        $this->actingAsAdmin();
        $a = $this->makePatient();
        $b = $this->makePatient(['full_name' => 'Otro Paciente Distinto', 'phone' => '5500000030', 'email' => 'o@x.mx']);
        $this->post(route('pacientes.fiscal.store', $a), $this->fiscal());
        $profile = $a->fiscalProfiles()->firstOrFail();

        $this->delete(route('pacientes.fiscal.destroy', [$b, $profile]))->assertNotFound();
        $this->post(route('pacientes.fiscal.default', [$b, $profile]))->assertNotFound();
        $this->post(route('pacientes.fiscal.store', $b), $this->fiscal(['profile_id' => $profile->id, 'rfc' => 'ROFI940820KT5']))
            ->assertSessionHasErrorsIn('fiscal', 'profile_id');
        $this->assertSame(1, $a->fiscalProfiles()->count());
    }

    // -------------------------------------------------------------- tutores

    public function test_guardian_create_edit_and_primary_switch(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(['birth_date' => now()->subYears(7)->toDateString()], null, ['full_name' => 'Madre Uno', 'phone' => '5511110000']);

        $this->post(route('pacientes.guardians.store', $p), ['full_name' => 'Padre Dos', 'relationship' => 'Padre', 'email' => 'padre@x.mx', 'is_primary' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $p->guardians()->where('is_primary', true)->count());
        $this->assertSame('Padre Dos', $p->guardians()->where('is_primary', true)->first()->full_name);

        $guardian = $p->guardians()->where('full_name', 'Padre Dos')->first();
        $this->post(route('pacientes.guardians.store', $p), ['guardian_id' => $guardian->id, 'full_name' => 'Padre Dos Actualizado', 'email' => 'padre@x.mx'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Padre Dos Actualizado', $guardian->fresh()->full_name);
        $this->assertTrue($guardian->fresh()->is_primary, 'no se puede quitar el principal sin designar otro');
    }

    public function test_guardian_validation(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient();

        $this->post(route('pacientes.guardians.store', $p), ['full_name' => ''])->assertSessionHasErrorsIn('guardian', 'full_name');
        $this->post(route('pacientes.guardians.store', $p), ['full_name' => 'Tutor Sin Contacto'])->assertSessionHasErrorsIn('guardian', ['phone', 'email']);
        $this->post(route('pacientes.guardians.store', $p), ['full_name' => 'Tutor', 'phone' => '12'])->assertSessionHasErrorsIn('guardian', 'phone');
        $this->post(route('pacientes.guardians.store', $p), ['full_name' => 'Tutor Ok', 'phone' => '5511110000', 'relationship' => 'Vecino'])->assertSessionHasErrorsIn('guardian', 'relationship');
        $this->assertSame(0, $p->guardians()->count());
    }

    public function test_last_guardian_of_a_minor_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(['birth_date' => now()->subYears(7)->toDateString()], null, ['full_name' => 'Madre Uno', 'phone' => '5511110000']);
        $only = $p->guardians()->firstOrFail();

        $this->delete(route('pacientes.guardians.destroy', [$p, $only]))->assertSessionHas('error');
        $this->assertSame(1, $p->guardians()->count());

        $this->post(route('pacientes.guardians.store', $p), ['full_name' => 'Padre Dos', 'phone' => '5511110001']);
        $this->delete(route('pacientes.guardians.destroy', [$p, $only]))->assertSessionHas('success');
        $this->assertSame(1, $p->guardians()->count());
        $this->assertTrue($p->guardians()->first()->is_primary, 'el tutor restante queda como principal');
    }

    public function test_adult_guardian_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient(['birth_date' => now()->subYears(40)->toDateString()], null, ['full_name' => 'Contacto Uno', 'phone' => '5511110000']);

        $this->delete(route('pacientes.guardians.destroy', [$p, $p->guardians()->firstOrFail()]))->assertSessionHas('success');
        $this->assertSame(0, $p->guardians()->count());
    }

    // ----------------------------------------------------------- demo data

    public function test_demo_seeder_is_idempotent_and_coherent(): void
    {
        $this->seed(DemoPacientesSeeder::class);
        $count = Patient::count();
        $this->assertGreaterThanOrEqual(10, $count);

        $this->seed(DemoPacientesSeeder::class);
        $this->assertSame($count, Patient::count(), 'no debe duplicar');

        // Todo paciente tiene expediente y folio único PAC-
        $this->assertSame($count, \App\Models\MedicalRecord::count());
        $this->assertSame($count, Patient::pluck('folio')->unique()->count());
        $this->assertTrue(Patient::pluck('folio')->every(fn ($f) => str_starts_with($f, 'PAC-')));

        // Los menores tienen tutor.
        Patient::with('guardians')->get()->each(function (Patient $p) {
            if ($p->birth_date && $p->birth_date->age < 18) {
                $this->assertGreaterThan(0, $p->guardians->count(), $p->full_name.' es menor y debe tener tutor');
            }
        });
        // Un solo perfil fiscal predeterminado por paciente.
        Patient::with('fiscalProfiles')->get()->each(fn ($p) => $this->assertLessThanOrEqual(1, $p->fiscalProfiles->where('is_default', true)->count()));
    }
}

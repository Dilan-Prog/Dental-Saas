<?php

namespace Tests\Feature\Configuracion;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClinicaTest extends ConfiguracionTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('configuracion.index'))->assertRedirect(route('login'));
        $this->put(route('configuracion.clinica.update'), $this->clinicData())->assertRedirect(route('login'));
    }

    public function test_admin_sees_clinic_form_with_subnav(): void
    {
        $this->actingAsAdmin();

        $this->get(route('configuracion.index'))
            ->assertOk()
            ->assertSee('Datos de la clínica')
            ->assertSee('Administra tu clínica')
            ->assertSee('Sucursales')
            ->assertSee('Owner')
            ->assertSee('Mi perfil');
    }

    public function test_doctor_and_receptionist_cannot_access_clinic_settings(): void
    {
        $this->actingAsDoctor();
        $this->get(route('configuracion.index'))->assertForbidden();
        $this->put(route('configuracion.clinica.update'), $this->clinicData())->assertForbidden();

        $this->actingAsReceptionist();
        $this->get(route('configuracion.index'))->assertForbidden();
    }

    public function test_clinic_data_is_saved_and_normalized(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.clinica.update'), $this->clinicData([
            'commercial_name' => '  Clínica Dental Pro  ',
            'rfc' => ' cus200115ab3 ',
            'email' => 'CONTACTO@Clinica.MX',
            'phone' => '+52 (55) 1234-5678',
        ]))->assertRedirect(route('configuracion.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('organizations', [
            'commercial_name' => 'Clínica Dental Pro',
            'rfc' => 'CUS200115AB3',
            'email' => 'contacto@clinica.mx',
            'phone' => '5512345678',
            'address_postal_code' => '06600',
        ]);
    }

    public function test_audit_log_stores_only_field_names_never_values(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.clinica.update'), $this->clinicData(['commercial_name' => 'Nombre Secreto Único']))
            ->assertSessionHasNoErrors();

        $row = DB::table('audit_log')->where('entity_type', 'organization')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('update', $row->action);
        $this->assertStringContainsString('commercial_name', (string) $row->changed_fields);
        $this->assertStringNotContainsString('Nombre Secreto Único', (string) $row->changed_fields);
    }

    /** @dataProvider invalidClinicData */
    public function test_invalid_clinic_data_is_rejected(array $override, string $field): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.clinica.update'), $this->clinicData($override))->assertSessionHasErrors($field);
    }

    public static function invalidClinicData(): array
    {
        return [
            'nombre vacío' => [['commercial_name' => ''], 'commercial_name'],
            'nombre largo' => [['commercial_name' => str_repeat('a', 201)], 'commercial_name'],
            'rfc inválido' => [['rfc' => 'ABC123'], 'rfc'],
            'rfc genérico público' => [['rfc' => 'XAXX010101000'], 'rfc'],
            'teléfono corto' => [['phone' => '12345'], 'phone'],
            'teléfono con letras' => [['phone' => 'abcdefghij'], 'phone'],
            'correo inválido' => [['email' => 'no-es-correo'], 'email'],
            'cp de 4 dígitos' => [['address_postal_code' => '0660'], 'address_postal_code'],
            'cp con letras' => [['address_postal_code' => '06A00'], 'address_postal_code'],
            'zona horaria fuera de catálogo' => [['timezone' => 'Europe/Madrid'], 'timezone'],
            'moneda no soportada' => [['currency' => 'USD'], 'currency'],
            'dirección larga' => [['address_line' => str_repeat('x', 301)], 'address_line'],
        ];
    }

    public function test_rfc_that_conflicts_with_the_tax_regime_warns_but_does_not_block(): void
    {
        $this->actingAsAdmin();
        // Régimen 601 es de persona moral (RFC de 12). Un RFC de 13 no es coherente: se avisa.
        $this->put(route('configuracion.facturacion.update'), $this->fiscalData())->assertSessionHasNoErrors();

        $this->put(route('configuracion.clinica.update'), $this->clinicData(['rfc' => 'PEGJ800101AB3']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('organizations', ['rfc' => 'PEGJ800101AB3']);
    }

    public function test_logo_can_be_uploaded_served_replaced_and_removed(): void
    {
        $this->actingAsAdmin();

        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertSessionHasNoErrors();

        $first = Organization::current()->logo_storage_key;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        $this->get(route('configuracion.clinica.logo'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => UploadedFile::fake()->image('nuevo.jpg', 200, 200)])
            ->assertSessionHasNoErrors();

        $second = Organization::current()->fresh()->logo_storage_key;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['remove_logo' => 1])->assertSessionHasNoErrors();
        $this->assertNull(Organization::current()->fresh()->logo_storage_key);
        Storage::disk('local')->assertMissing($second);
        $this->get(route('configuracion.clinica.logo'))->assertNotFound();
    }

    public function test_safe_svg_logo_is_accepted_but_svg_with_script_is_rejected(): void
    {
        $this->actingAsAdmin();

        $ok = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $ok])->assertSessionHasNoErrors();

        $bad = UploadedFile::fake()->createWithContent('malo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $bad])->assertSessionHasErrors('logo');
    }

    public function test_invalid_logo_files_are_rejected(): void
    {
        $this->actingAsAdmin();

        $tooBig = UploadedFile::fake()->image('grande.png', 100, 100)->size(3000);
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $tooBig])->assertSessionHasErrors('logo');

        $notImage = UploadedFile::fake()->createWithContent('falso.png', 'esto no es una imagen');
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $notImage])->assertSessionHasErrors('logo');

        $pdf = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $pdf])->assertSessionHasErrors('logo');

        $huge = UploadedFile::fake()->image('enorme.png', 2500, 2500);
        $this->put(route('configuracion.clinica.update'), $this->clinicData() + ['logo' => $huge])->assertSessionHasErrors('logo');
    }
}

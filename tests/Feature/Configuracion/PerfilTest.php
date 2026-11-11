<?php

namespace Tests\Feature\Configuracion;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class PerfilTest extends ConfiguracionTestCase
{
    private function profileData(User $user, array $overrides = []): array
    {
        return array_merge(['full_name' => $user->full_name, 'email' => $user->email], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('configuracion.perfil'))->assertRedirect(route('login'));
        $this->put(route('configuracion.perfil.update'), [])->assertRedirect(route('login'));
        $this->put(route('configuracion.perfil.password'), [])->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_its_own_profile(): void
    {
        foreach (['actingAsAdmin', 'actingAsDoctor', 'actingAsReceptionist'] as $login) {
            $user = $this->{$login}();
            $this->get(route('configuracion.perfil'))->assertOk()->assertSee('Mi perfil')->assertSee($user->full_name);
        }
    }

    public function test_receptionist_only_sees_profile_in_subnav(): void
    {
        $this->actingAsReceptionist();

        $this->get(route('configuracion.perfil'))->assertOk()->assertSee('Mi perfil')
            ->assertDontSee(route('configuracion.sucursales'), false)
            ->assertDontSee(route('configuracion.notificaciones'), false)
            ->assertDontSee(route('configuracion.facturacion'), false);
    }

    public function test_name_and_email_are_updated_and_normalized(): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, ['full_name' => '  Ana   María  López ', 'email' => 'ANA@Clinica.mx']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.perfil'))
            ->assertSessionHas('status', 'profile-updated');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'full_name' => 'Ana María López', 'email' => 'ana@clinica.mx']);
    }

    /** @dataProvider invalidProfiles */
    public function test_invalid_profile_data_is_rejected(array $override, string $field): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, $override))->assertSessionHasErrors($field);
    }

    public static function invalidProfiles(): array
    {
        return [
            'nombre vacío' => [['full_name' => ''], 'full_name'],
            'nombre muy corto' => [['full_name' => 'Al'], 'full_name'],
            'nombre largo' => [['full_name' => str_repeat('a', 201)], 'full_name'],
            'correo vacío' => [['email' => ''], 'email'],
            'correo inválido' => [['email' => 'sin-arroba'], 'email'],
            'correo de otro usuario' => [['email' => 'admin@dental.test'], 'email'],
        ];
    }

    public function test_user_can_keep_its_own_email(): void
    {
        $user = $this->actingAsAdmin();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user))->assertSessionHasNoErrors();
    }

    public function test_avatar_is_stored_privately_served_and_removable(): void
    {
        $user = $this->actingAsReceptionist();
        $this->get(route('configuracion.perfil.avatar'))->assertNotFound();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user) + ['avatar' => UploadedFile::fake()->image('yo.jpg', 200, 200)])
            ->assertSessionHasNoErrors();

        $key = $user->fresh()->avatar_storage_key;
        $this->assertNotNull($key);
        Storage::disk('local')->assertExists($key);
        $this->get(route('configuracion.perfil.avatar'))->assertOk();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user) + ['remove_avatar' => 1])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->avatar_storage_key);
        Storage::disk('local')->assertMissing($key);
    }

    public function test_avatar_rejects_svg_and_non_images(): void
    {
        $user = $this->actingAsReceptionist();

        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->put(route('configuracion.perfil.update'), $this->profileData($user) + ['avatar' => $svg])->assertSessionHasErrors('avatar');

        $txt = UploadedFile::fake()->createWithContent('a.jpg', 'no soy una imagen');
        $this->put(route('configuracion.perfil.update'), $this->profileData($user) + ['avatar' => $txt])->assertSessionHasErrors('avatar');
    }

    public function test_doctor_can_update_license_specialty_and_signature(): void
    {
        $user = $this->actingAsDoctor();
        $memberId = $this->memberId($user);

        $this->get(route('configuracion.perfil'))->assertOk()->assertSee('Cédula profesional')->assertSee('Firma digital');

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, [
            'professional_license' => '1234567',
            'specialty' => 'Ortodoncia',
        ]) + ['signature' => UploadedFile::fake()->image('firma.png', 400, 100)])->assertSessionHasNoErrors();

        $profile = DB::table('doctor_profiles')->where('member_id', $memberId)->first();
        $this->assertSame('1234567', $profile->professional_license);
        $this->assertSame('Ortodoncia', $profile->specialty);
        Storage::disk('local')->assertExists($profile->signature_image_storage_key);
        $this->get(route('configuracion.perfil.firma'))->assertOk();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, ['professional_license' => '1234567']) + ['remove_signature' => 1])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('doctor_profiles')->where('member_id', $memberId)->value('signature_image_storage_key'));
        $this->get(route('configuracion.perfil.firma'))->assertNotFound();
    }

    /** @dataProvider invalidDoctorData */
    public function test_invalid_doctor_data_is_rejected(array $override, string $field): void
    {
        $user = $this->actingAsDoctor();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, $override))->assertSessionHasErrors($field);
    }

    public static function invalidDoctorData(): array
    {
        return [
            'cédula con letras' => [['professional_license' => '12AB567'], 'professional_license'],
            'cédula muy corta' => [['professional_license' => '123456'], 'professional_license'],
            'cédula muy larga' => [['professional_license' => '123456789'], 'professional_license'],
            'especialidad larga' => [['specialty' => str_repeat('e', 121)], 'specialty'],
        ];
    }

    public function test_signature_must_be_png(): void
    {
        $user = $this->actingAsDoctor();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user) + ['signature' => UploadedFile::fake()->image('firma.jpg', 300, 100)])
            ->assertSessionHasErrors('signature');
    }

    public function test_non_doctors_cannot_set_doctor_fields(): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.update'), $this->profileData($user, ['professional_license' => '1234567']))->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('doctor_profiles')->where('member_id', $this->memberId($user))->count());
        $this->get(route('configuracion.perfil'))->assertOk()->assertDontSee('Cédula profesional');
    }

    public function test_password_can_be_changed_with_the_current_one(): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.password'), [
            'current_password' => 'password',
            'password' => 'NuevaClave2025',
            'password_confirmation' => 'NuevaClave2025',
        ])->assertSessionHasNoErrors(null, 'password')->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('NuevaClave2025', $user->fresh()->password));
    }

    /** @dataProvider invalidPasswords */
    public function test_invalid_password_changes_are_rejected(array $data, string $field): void
    {
        $user = $this->actingAsReceptionist();

        $this->put(route('configuracion.perfil.password'), $data)->assertSessionHasErrorsIn('password', $field);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public static function invalidPasswords(): array
    {
        return [
            'actual incorrecta' => [['current_password' => 'incorrecta', 'password' => 'NuevaClave2025', 'password_confirmation' => 'NuevaClave2025'], 'current_password'],
            'sin actual' => [['password' => 'NuevaClave2025', 'password_confirmation' => 'NuevaClave2025'], 'current_password'],
            'confirmación distinta' => [['current_password' => 'password', 'password' => 'NuevaClave2025', 'password_confirmation' => 'OtraClave2025'], 'password'],
            'muy corta' => [['current_password' => 'password', 'password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
            'sin números' => [['current_password' => 'password', 'password' => 'SoloLetrasAqui', 'password_confirmation' => 'SoloLetrasAqui'], 'password'],
            'sin letras' => [['current_password' => 'password', 'password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
            'igual a la actual' => [['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], 'password'],
        ];
    }
}

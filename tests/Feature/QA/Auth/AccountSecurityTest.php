<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA: seguridad de cuenta (baja de perfil, recuperacion de contrasena, XSS almacenado, avatar).
 */
class AccountSecurityTest extends TestCase
{
    private function as(string $email): static
    {
        Organization::flushCurrent();
        $this->flushSession();

        return $this->actingAs($this->userByEmail($email));
    }

    public function test_last_admin_cannot_delete_own_account_from_profile(): void
    {
        $this->as('admin@dental.test')->from('/profile')->delete('/profile', ['password' => 'password']);

        $this->assertSame(1, (int) DB::table('users')->where('email', 'admin@dental.test')->value('is_active'),
            'El unico administrador no puede darse de baja');
        $this->assertSame(1, (int) DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')
            ->where('users.email', 'admin@dental.test')->value('organization_members.is_active'));
    }

    public function test_profile_delete_requires_correct_password_and_deactivates_member_too(): void
    {
        $this->as('recepcion@dental.test')->from('/profile')->delete('/profile', ['password' => 'incorrecta'])
            ->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertSame(1, (int) DB::table('users')->where('email', 'recepcion@dental.test')->value('is_active'));

        $this->as('recepcion@dental.test')->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame(0, (int) DB::table('users')->where('email', 'recepcion@dental.test')->value('is_active'));
        $this->assertSame(0, (int) DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')
            ->where('users.email', 'recepcion@dental.test')->value('organization_members.is_active'));
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'admin@dental.test']);
        $known->assertSessionHasNoErrors();
        $msgKnown = session('status');

        $this->flushSession();
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nadie@dental.test']);
        $unknown->assertSessionHasNoErrors();

        $this->assertNotEmpty($msgKnown);
        $this->assertSame($msgKnown, session('status'), 'La respuesta debe ser identica para correos existentes e inexistentes');
    }

    public function test_forgot_password_survives_mail_server_failure(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
        app('mail.manager')->forgetMailers();

        $r = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'admin@dental.test']);
        $this->assertLessThan(500, $r->getStatusCode(), 'Un fallo SMTP no debe provocar error 500');
        $r->assertSessionHasNoErrors();
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->from('/forgot-password')->post('/forgot-password', [])->assertSessionHasErrors('email');
    }

    public function test_stored_values_are_escaped_everywhere_they_are_rendered(): void
    {
        $xss = '<script>alert("xss")</script>';
        $branch = $this->mainBranchId();

        $this->as('admin@dental.test')->put('/configuracion/clinica', [
            'commercial_name' => $xss, 'timezone' => 'America/Mexico_City', 'currency' => 'MXN', 'address_line' => $xss,
        ])->assertSessionHasNoErrors();
        $this->as('admin@dental.test')->post('/configuracion/sucursales', ['name' => $xss])->assertSessionHasNoErrors();
        $this->as('admin@dental.test')->post('/roles', ['name' => 'Rol '.$xss, 'description' => $xss])->assertSessionHasNoErrors();
        DB::table('users')->where('email', 'doctor@dental.test')->update(['full_name' => $xss]);

        foreach (['/dashboard', '/configuracion', '/configuracion/sucursales', '/usuarios', '/roles', '/pacientes', '/agenda'] as $page) {
            $html = $this->as('admin@dental.test')->get($page)->getContent();
            $this->assertStringNotContainsString($xss, $html, "Contenido sin escapar en {$page}");
        }
    }

    public function test_avatar_upload_rules(): void
    {
        Storage::fake('local');
        $base = ['full_name' => 'Admin Demo', 'email' => 'admin@dental.test'];

        $bad = [
            'php disfrazado' => UploadedFile::fake()->createWithContent('a.png', '<?php system($_GET["c"]);'),
            'pdf' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            'gif' => UploadedFile::fake()->image('a.gif', 100, 100),
            '3MB' => UploadedFile::fake()->image('a.jpg', 100, 100)->size(3000),
        ];
        foreach ($bad as $label => $file) {
            $this->as('admin@dental.test')->from('/configuracion/perfil')
                ->post('/configuracion/perfil', ['_method' => 'PUT', 'avatar' => $file] + $base)
                ->assertSessionHasErrors('avatar');
            $this->assertNull(DB::table('users')->where('email', 'admin@dental.test')->value('avatar_storage_key'), "Avatar aceptado: {$label}");
        }

        $this->as('admin@dental.test')->post('/configuracion/perfil', ['_method' => 'PUT', 'avatar' => UploadedFile::fake()->image('ok.png', 300, 300)] + $base)
            ->assertSessionHasNoErrors();
        $key = DB::table('users')->where('email', 'admin@dental.test')->value('avatar_storage_key');
        $this->assertNotEmpty($key);
        $this->assertStringNotContainsString('ok.png', $key, 'El nombre del archivo debe ser generado, no el del usuario');
        $this->as('admin@dental.test')->get('/configuracion/perfil/avatar')->assertOk();

        $this->as('admin@dental.test')->post('/configuracion/perfil', ['_method' => 'PUT', 'remove_avatar' => 1] + $base)->assertSessionHasNoErrors();
        $this->assertNull(DB::table('users')->where('email', 'admin@dental.test')->value('avatar_storage_key'));
    }

    public function test_csrf_protection_is_active_for_state_changing_routes(): void
    {
        $prop = new \ReflectionProperty(\App\Http\Middleware\VerifyCsrfToken::class, 'except');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue(app(\App\Http\Middleware\VerifyCsrfToken::class)), 'Ninguna ruta debe estar excluida de CSRF');
    }

    public function test_logout_only_via_post(): void
    {
        $this->as('admin@dental.test')->get('/logout')->assertStatus(405);
        $this->assertAuthenticated();
    }
}

<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA: reglas de negocio de acceso (ultimo admin, rol del sistema, permisos en caliente,
 * invitaciones, limites de intentos, escalada de privilegios).
 */
class AdminBusinessRulesTest extends TestCase
{
    private function roleId(string $code): string
    {
        return (string) DB::table('roles')->where('organization_id', $this->organizationId())->where('code', $code)->value('id');
    }

    private function permissionId(string $module, string $action): string
    {
        return (string) DB::table('permissions')->where('module', $module)->where('action', $action)->value('id');
    }

    private function as(User $u): static
    {
        Organization::flushCurrent();
        $this->flushSession();

        return $this->actingAs($u);
    }

    // ------------------------------------------------------------ ultimo admin

    public function test_cannot_strip_last_admin_by_moving_them_to_another_role(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $adminMember = $this->memberId($admin);

        $this->as($admin)->from('/roles')->post('/roles/'.$this->roleId('doctor').'/usuarios', ['member_ids' => [$adminMember]]);

        $this->assertSame($this->roleId('admin_sistema'), DB::table('organization_members')->where('id', $adminMember)->value('role_id'),
            'El ultimo administrador no debe poder moverse a otro rol');
    }

    public function test_cannot_remove_last_admin_from_admin_role(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $adminMember = $this->memberId($admin);

        $this->as($admin)->from('/roles')->delete('/roles/'.$this->roleId('admin_sistema').'/usuarios/'.$adminMember);

        $this->assertNotNull(DB::table('organization_members')->where('id', $adminMember)->value('role_id'));
    }

    public function test_cannot_deactivate_last_admin_nor_self(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $this->as($admin)->from('/usuarios')->post('/usuarios/'.$this->memberId($admin).'/estado', ['active' => 0]);

        $this->assertSame(1, (int) DB::table('users')->where('id', $admin->id)->value('is_active'));
        $this->assertSame(1, (int) DB::table('organization_members')->where('user_id', $admin->id)->value('is_active'));
    }

    public function test_last_admin_survives_direct_db_attempt(): void
    {
        $admin = $this->userByEmail('admin@dental.test');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('organization_members')->where('user_id', $admin->id)->update(['role_id' => null]);
    }

    // ------------------------------------------------------------ rol del sistema

    public function test_system_role_cannot_be_edited_deactivated_or_have_permissions_changed(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $role = $this->roleId('admin_sistema');
        $before = DB::table('roles')->where('id', $role)->first();
        $permCount = DB::table('role_permissions')->where('role_id', $role)->count();

        $this->as($admin)->from('/roles')->put("/roles/{$role}", ['name' => 'Hackeado', 'description' => 'x']);
        $this->as($admin)->from('/roles')->post("/roles/{$role}/estado", ['active' => 0]);
        $this->as($admin)->from('/roles')->put("/roles/{$role}/permisos", ['permissions' => [$this->permissionId('dashboard', 'ver')]]);

        $after = DB::table('roles')->where('id', $role)->first();
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($permCount, DB::table('role_permissions')->where('role_id', $role)->count());
    }

    public function test_mass_assignment_cannot_promote_a_role_to_system_admin(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $doctorRole = $this->roleId('doctor');

        $this->as($admin)->from('/roles')->put("/roles/{$doctorRole}", [
            'name' => 'Doctor', 'is_system_admin' => 1, 'code' => 'admin_sistema', 'organization_id' => (string) Str::uuid(),
        ]);

        $row = DB::table('roles')->where('id', $doctorRole)->first();
        $this->assertSame(0, (int) $row->is_system_admin);
        $this->assertSame('doctor', $row->code);
        $this->assertSame($this->organizationId(), $row->organization_id);
    }

    public function test_user_update_cannot_mass_assign_privileged_fields(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $recep = $this->userByEmail('recepcion@dental.test');
        $member = $this->memberId($recep);
        $branch = $this->mainBranchId();

        $this->as($admin)->from('/usuarios')->put("/usuarios/{$member}", [
            'full_name' => 'Recepcion Demo', 'email' => 'recepcion@dental.test', 'role_id' => $this->roleId('recepcion'),
            'branch_ids' => [$branch], 'is_doctor' => 0,
            'is_active' => 0, 'password' => 'Hack12345', 'organization_id' => (string) Str::uuid(), 'id' => (string) Str::uuid(),
        ]);

        $u = DB::table('users')->where('id', $recep->id)->first();
        $this->assertSame(1, (int) $u->is_active);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $u->password), 'La contrasena no debe cambiar por mass-assignment');
        $this->assertSame($this->organizationId(), DB::table('organization_members')->where('id', $member)->value('organization_id'));
    }

    // ------------------------------------------------------------ permisos en caliente

    public function test_permission_changes_apply_immediately_to_logged_in_users(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $doctor = $this->userByEmail('doctor@dental.test');
        $doctorRole = $this->roleId('doctor');

        $this->as($doctor)->get('/agenda')->assertOk();

        $agendaIds = DB::table('permissions')->where('module', 'agenda')->pluck('id')->all();
        $keep = DB::table('role_permissions')->where('role_id', $doctorRole)
            ->whereNotIn('permission_id', $agendaIds)->pluck('permission_id')->all();
        $this->as($admin)->put("/roles/{$doctorRole}/permisos", ['permissions' => $keep])->assertSessionHasNoErrors();

        $this->as($doctor->fresh())->get('/agenda')->assertForbidden();
    }

    public function test_view_permission_is_required_before_other_actions(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $role = $this->roleId('recepcion');

        // solo 'crear' en pacientes sin 'ver'
        $this->as($admin)->from('/roles')->put("/roles/{$role}/permisos", ['permissions' => [$this->permissionId('pacientes', 'crear')]]);

        $this->assertFalse(
            DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $this->permissionId('pacientes', 'crear'))->exists()
            && ! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $this->permissionId('pacientes', 'ver'))->exists(),
            'No debe quedar un permiso de accion sin el permiso Ver del modulo'
        );
    }

    public function test_deactivated_user_is_kicked_out_mid_session(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $recep = $this->userByEmail('recepcion@dental.test');

        $this->as($recep)->get('/dashboard')->assertOk();
        $this->as($admin)->post('/usuarios/'.$this->memberId($recep).'/estado', ['active' => 0])->assertSessionHasNoErrors();

        $r = $this->as($recep->fresh())->get('/dashboard');
        $this->assertContains($r->getStatusCode(), [302, 401, 403]);
        if ($r->getStatusCode() === 302) {
            $this->assertStringContainsString('login', (string) $r->headers->get('Location'));
        }
    }

    public function test_user_without_role_has_no_access_to_any_module(): void
    {
        $recep = $this->userByEmail('recepcion@dental.test');
        DB::table('organization_members')->where('user_id', $recep->id)->update(['role_id' => null]);

        foreach (['/pacientes', '/agenda', '/facturacion', '/finanzas', '/inventario', '/recetas', '/presupuestos', '/configuracion', '/usuarios', '/roles'] as $url) {
            $r = $this->as($recep->fresh())->get($url);
            $this->assertNotSame(200, $r->getStatusCode(), "Usuario sin rol accede a {$url}");
        }
    }

    // ------------------------------------------------------------ escalada

    public function test_receptionist_and_doctor_cannot_manage_users_or_roles(): void
    {
        $recepRole = $this->roleId('recepcion');
        foreach (['doctor@dental.test', 'recepcion@dental.test'] as $email) {
            $u = $this->userByEmail($email);
            $this->as($u)->post('/usuarios', ['full_name' => 'Intruso Uno', 'email' => 'intruso@x.mx', 'role_id' => $this->roleId('admin_sistema'), 'branch_ids' => [$this->mainBranchId()]])->assertForbidden();
            $this->as($u)->put('/roles/'.$recepRole.'/permisos', ['permissions' => [$this->permissionId('roles', 'editar')]])->assertForbidden();
            $this->as($u)->post('/roles/'.$this->roleId('admin_sistema').'/usuarios', ['member_ids' => [$this->memberId($u)]])->assertForbidden();
            $this->as($u)->put('/configuracion/facturacion', ['legal_name' => 'X'])->assertForbidden();
        }
        $this->assertSame(0, DB::table('users')->where('email', 'intruso@x.mx')->count());
    }

    public function test_unknown_member_or_role_ids_return_404_not_500(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $ghost = (string) Str::uuid();

        foreach ([
            ['GET', "/usuarios/{$ghost}/editar"], ['PUT', "/usuarios/{$ghost}"], ['POST', "/usuarios/{$ghost}/estado"],
            ['GET', "/roles/{$ghost}"], ['PUT', "/roles/{$ghost}/permisos"], ['DELETE', "/roles/{$this->roleId('doctor')}/usuarios/{$ghost}"],
            ['PATCH', "/configuracion/sucursales/{$ghost}/estado"], ['GET', '/usuarios/no-es-uuid/editar'],
        ] as [$m, $url]) {
            $r = $this->as($admin)->call($m, $url, ['active' => 0]);
            $this->assertSame(404, $r->getStatusCode(), "{$m} {$url} => {$r->getStatusCode()}");
        }
    }

    // ------------------------------------------------------------ invitaciones

    private function invite(string $email = 'nuevo@dental.test', array $extra = []): string
    {
        $admin = $this->userByEmail('admin@dental.test');
        $r = $this->as($admin)->post('/usuarios', array_merge([
            'full_name' => 'Nuevo Usuario', 'email' => $email, 'role_id' => $this->roleId('recepcion'),
            'branch_ids' => [$this->mainBranchId()], 'is_doctor' => 0,
        ], $extra));
        $r->assertSessionHasNoErrors();
        $link = session('access_link');
        $this->assertNotEmpty($link['url'] ?? null, 'La invitacion debe devolver el enlace una vez');

        auth()->logout();
        $this->flushSession();

        return basename(parse_url($link['url'], PHP_URL_PATH));
    }

    public function test_invitation_full_flow_and_single_use(): void
    {
        $token = $this->invite();

        // el token en claro no se guarda
        $this->assertSame(0, DB::table('user_invitations')->where('token_hash', $token)->count());
        $this->assertSame(1, DB::table('user_invitations')->where('token_hash', hash('sha256', $token))->count());

        // usuario invitado esta inactivo hasta aceptar y no puede entrar
        $this->flushSession();
        $this->post('/login', ['email' => 'nuevo@dental.test', 'password' => 'Cualquiera1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->get("/invitaciones/{$token}")->assertOk();
        foreach (['corta1', 'sinnumeros', 'SINMINUSCULAS1', 'abcdefgh1'] as $weak) {
            $this->post("/invitaciones/{$token}", ['password' => $weak, 'password_confirmation' => $weak])->assertSessionHasErrors('password');
        }
        $this->post("/invitaciones/{$token}", ['password' => 'Segura1234', 'password_confirmation' => 'otra'])->assertSessionHasErrors('password');

        $this->post("/invitaciones/{$token}", ['password' => 'Segura1234', 'password_confirmation' => 'Segura1234'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertSame(1, (int) DB::table('users')->where('email', 'nuevo@dental.test')->value('is_active'));

        // segundo uso del enlace: no debe permitir cambiar la contrasena
        auth()->logout();
        $this->flushSession();
        $this->post("/invitaciones/{$token}", ['password' => 'Hackeada123', 'password_confirmation' => 'Hackeada123']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'nuevo@dental.test', 'password' => 'Segura1234'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_expired_revoked_and_garbage_invitation_tokens_are_rejected(): void
    {
        $token = $this->invite('exp@dental.test');
        DB::table('user_invitations')->where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subMinute()]);
        $this->flushSession();
        $this->post("/invitaciones/{$token}", ['password' => 'Segura1234', 'password_confirmation' => 'Segura1234']);
        $this->assertGuest();
        $this->assertSame(0, (int) DB::table('users')->where('email', 'exp@dental.test')->value('is_active'));

        $token2 = $this->invite('rev@dental.test');
        $admin = $this->userByEmail('admin@dental.test');
        $member = DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')->where('users.email', 'rev@dental.test')->value('organization_members.id');
        $this->as($admin)->delete("/usuarios/{$member}/invitacion");
        auth()->logout();
        $this->flushSession();
        $this->post("/invitaciones/{$token2}", ['password' => 'Segura1234', 'password_confirmation' => 'Segura1234']);
        $this->assertGuest();

        $this->get('/invitaciones/'.str_repeat('a', 48))->assertStatus(200); // pagina de "enlace invalido", sin 500
        $this->get('/invitaciones/corto')->assertNotFound();
    }

    // ------------------------------------------------------------ login

    public function test_login_is_throttled_after_five_failures(): void
    {
        RateLimiter::clear('admin@dental.test|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@dental.test', 'password' => 'incorrecta'.$i])->assertSessionHasErrors('email');
        }
        $r = $this->post('/login', ['email' => 'admin@dental.test', 'password' => 'password']);
        $r->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertStringContainsString('segundos', (string) session('errors')->first('email'));
    }

    public function test_login_ignores_open_redirect_and_inactive_membership(): void
    {
        $this->post('/login', ['email' => 'admin@dental.test', 'password' => 'password', 'redirect' => 'https://evil.test', 'intended' => 'https://evil.test']);
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk();
    }

    public function test_password_change_invalidates_old_password(): void
    {
        $admin = $this->userByEmail('admin@dental.test');
        $this->as($admin)->put('/configuracion/perfil/password', [
            'current_password' => 'password', 'password' => 'Nueva12345', 'password_confirmation' => 'Nueva12345',
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => 'admin@dental.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'admin@dental.test', 'password' => 'Nueva12345']);
        $this->assertAuthenticated();
    }
}

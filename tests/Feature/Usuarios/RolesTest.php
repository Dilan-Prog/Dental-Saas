<?php

namespace Tests\Feature\Usuarios;

use App\Models\Role;
use App\Models\RoleChangeHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolesTest extends UsuariosTestCase
{
    // ------------------------------------------------------------ acceso

    public function test_guests_and_unauthorized_users_cannot_manage_roles(): void
    {
        $this->get(route('roles.index'))->assertRedirect(route('login'));

        $this->actingAsDoctor();
        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('roles.show', $this->role('doctor')))->assertForbidden();
        $this->post(route('roles.store'), ['name' => 'Nuevo'])->assertForbidden();
    }

    public function test_index_lists_roles_with_user_counts_and_system_lock(): void
    {
        $this->actingAsAdmin();

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Administrador')->assertSee('Sistema')->assertSee('Bloqueado')
            ->assertSee('Doctor')->assertSee('Nuevo rol');
    }

    // ---------------------------------------------------------- alta / edición

    public function test_creating_a_role_generates_a_unique_code_and_logs_it(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->post(route('roles.store'), ['name' => 'Jefe de odontología', 'description' => 'Odontólogo con reportes']);

        $role = Role::where('name', 'Jefe de odontología')->firstOrFail();
        $response->assertRedirect(route('roles.show', $role));
        $this->assertSame('jefe_de_odontologia', $role->code);
        $this->assertSame('active', $role->status->value);
        $this->assertFalse($role->is_system_admin);
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('role_change_history', ['role_id' => $role->id, 'change_type' => 'role_created', 'actor_user_id' => $admin->id]);

        // Un nombre distinto que produce el mismo slug recibe un código distinto.
        $this->post(route('roles.store'), ['name' => 'Jefe-de odontología']);
        $this->assertSame('jefe_de_odontologia_2', Role::where('name', 'Jefe-de odontología')->value('code'));

        // El nombre es único sin distinguir acentos ni mayúsculas.
        $this->post(route('roles.store'), ['name' => 'JEFE DE ODONTOLOGIA'])->assertSessionHasErrors('name', null, 'role');
    }

    public function test_role_name_validations(): void
    {
        $this->actingAsAdmin();

        $this->post(route('roles.store'), ['name' => ''])->assertSessionHasErrors('name', null, 'role');
        $this->post(route('roles.store'), ['name' => 'ab'])->assertSessionHasErrors('name', null, 'role');
        $this->post(route('roles.store'), ['name' => str_repeat('x', 101)])->assertSessionHasErrors('name', null, 'role');
        $this->post(route('roles.store'), ['name' => 'Valido', 'description' => str_repeat('x', 256)])->assertSessionHasErrors('description', null, 'role');
        // Único sin distinguir mayúsculas
        $this->post(route('roles.store'), ['name' => 'doctor'])->assertSessionHasErrors('name', null, 'role');
    }

    public function test_updating_a_custom_role_and_blocking_the_system_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Auxiliar');

        $this->put(route('roles.update', $role), ['name' => 'Auxiliar dental', 'description' => 'Apoya a los doctores'])->assertSessionHas('success');
        $this->assertSame('Auxiliar dental', $role->fresh()->name);
        $this->assertDatabaseHas('role_change_history', ['role_id' => $role->id, 'change_type' => 'role_updated']);

        // Mismo nombre que otro rol → error; el propio nombre es válido.
        $this->put(route('roles.update', $role), ['name' => 'Doctor'])->assertSessionHasErrors('name', null, 'role_edit');
        $this->put(route('roles.update', $role), ['name' => 'Auxiliar dental'])->assertSessionHasNoErrors();

        $system = $this->role('admin_sistema');
        $this->put(route('roles.update', $system), ['name' => 'Otro'])->assertSessionHas('error');
        $this->assertSame('Administrador', $system->fresh()->name);
    }

    // -------------------------------------------------------------- permisos

    public function test_the_show_page_renders_the_matrix_tabs_and_menu_preview(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver', 'agenda.ver', 'agenda.crear']);

        $this->get(route('roles.show', $role))
            ->assertOk()
            ->assertSee('Permisos')->assertSee('Usuarios asignados')->assertSee('Historial de cambios')->assertSee('Vista previa del menú')
            ->assertSee('Así verá el menú este rol')->assertSee('Guardar cambios')
            ->assertSee('Firmar notas')->assertSee('Cancelar CFDI')->assertSee('Cerrar caja');
    }

    public function test_saving_permissions_syncs_them_logs_the_diff_and_revokes_sessions(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);
        $member = $this->makeMember('recepcion');
        $member->update(['role_id' => $role->id]);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $member->user_id,
            'organization_id' => $member->organization_id, 'expires_at' => now()->addDay(),
        ]);

        $this->put(route('roles.permisos', $role), ['permissions' => [
            $this->permissionId('dashboard', 'ver'),
            $this->permissionId('agenda', 'ver'),
            $this->permissionId('agenda', 'crear'),
        ]])->assertRedirect(route('roles.show', $role))->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(['dashboard.ver', 'agenda.ver', 'agenda.crear'], app(\App\Services\Usuarios\RoleService::class)->grantedKeys($role));

        $log = RoleChangeHistory::where('role_id', $role->id)->where('change_type', 'role_permissions_changed')->firstOrFail();
        $this->assertEqualsCanonicalizing(['agenda.ver', 'agenda.crear'], $log->new_value['added']);
        $this->assertSame([], $log->new_value['removed']);
        $this->assertSame($admin->id, $log->actor_user_id);

        $session = DB::table('user_sessions')->where('user_id', $member->user_id)->first();
        $this->assertNotNull($session->revoked_at);
        $this->assertSame('permissions_changed', $session->revoked_reason);

        // El usuario del rol ya puede (y solo puede) lo concedido.
        $user = $member->user->fresh();
        $this->assertTrue($user->hasPermission('agenda', 'crear'));
        $this->assertFalse($user->hasPermission('finanzas', 'ver'));
    }

    public function test_saving_the_same_permissions_does_not_log_anything(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);
        $before = RoleChangeHistory::count();

        $this->put(route('roles.permisos', $role), ['permissions' => [$this->permissionId('dashboard', 'ver')]])
            ->assertSessionHas('info');

        $this->assertSame($before, RoleChangeHistory::count());
    }

    public function test_permissions_other_than_view_require_view_in_the_same_module(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente');

        $this->put(route('roles.permisos', $role), ['permissions' => [$this->permissionId('agenda', 'crear')]])
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
    }

    public function test_invalid_permission_payloads_are_rejected(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);

        $this->put(route('roles.permisos', $role), ['permissions' => ['no-es-uuid']])->assertSessionHasErrors('permissions.0');
        $this->put(route('roles.permisos', $role), ['permissions' => ['11111111-1111-4111-8111-111111111111']])->assertSessionHasErrors('permissions.0');
        $id = $this->permissionId('agenda', 'ver');
        $this->put(route('roles.permisos', $role), ['permissions' => [$id, $id]])->assertSessionHasErrors('permissions.0');

        $this->assertSame(['dashboard.ver'], app(\App\Services\Usuarios\RoleService::class)->grantedKeys($role));
    }

    public function test_unchecking_everything_empties_the_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver', 'agenda.ver']);

        $this->put(route('roles.permisos', $role), [])->assertSessionHas('success');

        $this->assertSame([], app(\App\Services\Usuarios\RoleService::class)->grantedKeys($role));
    }

    public function test_the_system_admin_role_is_locked(): void
    {
        $this->actingAsAdmin();
        $system = $this->role('admin_sistema');

        $this->get(route('roles.show', $system))->assertOk()->assertSee('Rol bloqueado')->assertDontSee('Guardar cambios');
        $this->put(route('roles.permisos', $system), ['permissions' => []])->assertSessionHas('error');
        $this->post(route('roles.estado', $system), ['active' => 0])->assertSessionHas('error');
        $this->post(route('roles.usuarios.asignar', $system), ['member_ids' => [$this->makeMember()->id]])->assertSessionHas('error');

        $this->assertSame('active', $system->fresh()->status->value);
        $this->assertTrue($system->fresh()->hasPermission('finanzas', 'cerrar_caja'));
    }

    // ------------------------------------------------------- activar / desactivar

    public function test_deactivating_a_role_reassigns_its_users(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->makeRole('Auxiliar de inventario', ['inventario.ver']);
        $a = $this->makeMember('recepcion');
        $b = $this->makeMember('recepcion');
        $a->update(['role_id' => $role->id]);
        $b->update(['role_id' => $role->id]);
        $target = $this->role('recepcion');

        // Sin elegir reemplazo no se puede.
        $this->post(route('roles.estado', $role), ['active' => 0])->assertSessionHas('error');
        $this->assertSame('active', $role->fresh()->status->value);

        $this->post(route('roles.estado', $role), ['active' => 0, 'replacement_role_id' => $target->id])
            ->assertRedirect(route('roles.index'))->assertSessionHas('success');

        $this->assertSame('inactive', $role->fresh()->status->value);
        $this->assertSame($target->id, $a->fresh()->role_id);
        $this->assertSame($target->id, $b->fresh()->role_id);
        $this->assertSame(2, RoleChangeHistory::where('change_type', 'user_role_assigned')->where('actor_user_id', $admin->id)->where('role_id', $target->id)->count());
        $this->assertDatabaseHas('role_change_history', ['role_id' => $role->id, 'change_type' => 'role_deactivated']);

        // Reactivar
        $this->post(route('roles.estado', $role), ['active' => 1])->assertSessionHas('success');
        $this->assertSame('active', $role->fresh()->status->value);
    }

    public function test_deactivating_an_empty_role_needs_no_replacement(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Vacío');

        $this->post(route('roles.estado', $role), ['active' => 0])->assertSessionHas('success');

        $this->assertSame('inactive', $role->fresh()->status->value);
    }

    public function test_replacement_role_must_be_a_different_active_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Auxiliar');
        $other = $this->makeRole('Inactivo');
        $other->update(['status' => 'inactive']);

        $this->post(route('roles.estado', $role), ['active' => 0, 'replacement_role_id' => $role->id])->assertSessionHasErrors('replacement_role_id', null, 'role_deactivate');
        $this->post(route('roles.estado', $role), ['active' => 0, 'replacement_role_id' => $other->id])->assertSessionHasErrors('replacement_role_id', null, 'role_deactivate');
    }

    public function test_you_cannot_deactivate_the_role_you_have(): void
    {
        $this->actingAsAdmin();
        $manager = $this->makeMember('recepcion');
        $role = $this->makeRole('Gestor', ['roles.ver', 'roles.desactivar']);
        $manager->update(['role_id' => $role->id]);
        $this->actingAs($manager->user);

        $this->post(route('roles.estado', $role), ['active' => 0, 'replacement_role_id' => $this->role('recepcion')->id])
            ->assertSessionHas('error', 'No puedes desactivar el rol que tú mismo tienes asignado.');
        $this->assertSame('active', $role->fresh()->status->value);
    }

    // ----------------------------------------------------- asignar / quitar usuarios

    public function test_assigning_users_moves_them_and_logs_history(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);
        $member = $this->makeMember('recepcion');

        $this->post(route('roles.usuarios.asignar', $role), ['member_ids' => [$member->id]])->assertSessionHas('success');

        $this->assertSame($role->id, $member->fresh()->role_id);
        $log = RoleChangeHistory::where('target_member_id', $member->id)->latest('created_at')->first();
        $this->assertSame('Recepción', $log->old_value['role']);
        $this->assertSame('Asistente', $log->new_value['role']);
        $this->assertSame($admin->id, $log->actor_user_id);

        $this->get(route('roles.show', $role))->assertSee($member->user->full_name);
    }

    public function test_assign_validations(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente');

        $this->post(route('roles.usuarios.asignar', $role), [])->assertSessionHasErrors('member_ids', null, 'role_assign');
        $this->post(route('roles.usuarios.asignar', $role), ['member_ids' => ['11111111-1111-4111-8111-111111111111']])
            ->assertSessionHasErrors('member_ids.0', null, 'role_assign');

        $inactive = $this->makeRole('Inactivo');
        $inactive->update(['status' => 'inactive']);
        $this->post(route('roles.usuarios.asignar', $inactive), ['member_ids' => [$this->makeMember()->id]])->assertSessionHas('error');
    }

    public function test_assigning_cannot_demote_the_last_admin_or_yourself(): void
    {
        $this->actingAsAdmin();
        $adminMember = $this->userByEmail('admin@dental.test')->member;
        $role = $this->makeRole('Asistente', ['dashboard.ver']);

        // Uno mismo
        $this->post(route('roles.usuarios.asignar', $role), ['member_ids' => [$adminMember->id]])
            ->assertSessionHas('error', 'No puedes cambiar tu propio rol.');

        // Último admin, desde otro gestor
        $manager = $this->makeMember('recepcion');
        $manager->update(['role_id' => $this->makeRole('Gestor', ['roles.ver', 'roles.editar'])->id]);
        $this->actingAs($manager->user);
        $this->post(route('roles.usuarios.asignar', $role), ['member_ids' => [$adminMember->id]])
            ->assertSessionHas('error', 'Debe existir al menos un administrador activo en la clínica.');
        $this->assertSame($this->role('admin_sistema')->id, $adminMember->fresh()->role_id);
    }

    public function test_removing_a_user_leaves_them_without_a_role_and_without_access(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);
        $member = $this->makeMember('recepcion');
        $member->update(['role_id' => $role->id]);

        $this->delete(route('roles.usuarios.quitar', [$role, $member]))->assertSessionHas('success');

        $this->assertNull($member->fresh()->role_id);
        $this->assertFalse($member->user->fresh()->hasPermission('dashboard', 'ver'));

        // No se puede quitar a alguien que no pertenece al rol.
        $this->delete(route('roles.usuarios.quitar', [$role, $member]))->assertSessionHas('error');
    }

    // ------------------------------------------------------- historial / vista previa

    public function test_history_tab_shows_changes_and_menu_preview_reflects_permissions(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Asistente', ['dashboard.ver']);
        $this->put(route('roles.permisos', $role), ['permissions' => [
            $this->permissionId('dashboard', 'ver'), $this->permissionId('finanzas', 'ver'),
        ]]);

        $page = $this->get(route('roles.show', $role))->assertOk();
        $page->assertSee('Permisos modificados: +1 / −0.')->assertSee('finanzas.ver');

        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/Finanzas<\/span>\s*<span class="badge badge--success">Visible/', $html);
        $this->assertMatchesRegularExpression('/Inventario<\/span>\s*<span class="badge badge--neutral">Oculto/', $html);
    }
}

<?php

namespace Tests\Feature\Usuarios;

use App\Models\DoctorProfile;
use App\Models\OrganizationMember;
use App\Models\RoleChangeHistory;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UsuariosTest extends UsuariosTestCase
{
    // ------------------------------------------------------------ acceso

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('usuarios.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_permission_get_403(): void
    {
        $this->actingAsReceptionist();
        $this->get(route('usuarios.index'))->assertForbidden();

        $this->actingAsDoctor();
        $this->post(route('usuarios.store'), $this->userData())->assertForbidden();
    }

    public function test_admin_sees_the_list_with_seeded_users(): void
    {
        $this->actingAsAdmin();

        $this->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('admin@dental.test')
            ->assertSee('doctor@dental.test')
            ->assertSee('Invitar usuario');
    }

    public function test_list_filters_by_search_role_and_status(): void
    {
        $this->actingAsAdmin();
        $this->makeMember('doctor', ['full_name' => 'Hugo Beltrán', 'email' => 'hugo@clinica.mx'], false);

        $this->get(route('usuarios.index', ['q' => 'hugo']))->assertSee('hugo@clinica.mx')->assertDontSee('recepcion@dental.test');
        $this->get(route('usuarios.index', ['role' => $this->role('recepcion')->id]))->assertSee('recepcion@dental.test')->assertDontSee('hugo@clinica.mx');
        $this->get(route('usuarios.index', ['status' => 'inactivo']))->assertSee('hugo@clinica.mx')->assertDontSee('recepcion@dental.test');
        $this->get(route('usuarios.index', ['status' => 'activo']))->assertSee('recepcion@dental.test')->assertDontSee('hugo@clinica.mx');
        $this->get(route('usuarios.index', ['status' => 'cualquiera']))->assertSessionHasErrors('status');
    }

    // ------------------------------------------------------ alta / invitación

    public function test_inviting_creates_an_inactive_user_with_a_hashed_single_use_link(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->post(route('usuarios.store'), $this->userData());

        $response->assertRedirect(route('usuarios.index'))->assertSessionHas('access_link');
        $link = session('access_link');

        $user = User::where('email', 'ana.lopez@clinica.mx')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertNull($user->password);

        $member = OrganizationMember::where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($member->is_active);
        $this->assertSame($this->role('doctor')->id, $member->role_id);
        $this->assertSame(1, DB::table('member_branches')->where('member_id', $member->id)->count());

        // Solo se guarda el hash del token, nunca el token en claro.
        $token = basename($link['url']);
        $invitation = UserInvitation::where('email', $user->email)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertSame($admin->member->id, $invitation->invited_by_member_id);

        // El historial registra la asignación inicial de rol.
        $this->assertTrue(RoleChangeHistory::where('target_member_id', $member->id)->where('change_type', 'user_role_assigned')->exists());

        // El enlace se muestra en la primera carga tras la redirección y nunca más.
        $this->get(route('usuarios.index'))->assertSee($token);
        $this->get(route('usuarios.index'))->assertDontSee($token);
    }

    public function test_invite_validations(): void
    {
        $this->actingAsAdmin();
        $cases = [
            'nombre vacío' => [['full_name' => ''], 'full_name'],
            'nombre con números' => [['full_name' => 'Ana 123'], 'full_name'],
            'correo inválido' => [['email' => 'no-es-correo'], 'email'],
            'rol inexistente' => [['role_id' => '11111111-1111-4111-8111-111111111111'], 'role_id'],
            'sin sucursales' => [['branch_ids' => []], 'branch_ids'],
            'sucursal inexistente' => [['branch_ids' => ['11111111-1111-4111-8111-111111111111']], 'branch_ids.0'],
            'doctor sin cédula' => [['is_doctor' => '1'], 'professional_license'],
            'cédula con letras' => [['is_doctor' => '1', 'professional_license' => '12AB567'], 'professional_license'],
            'cédula corta' => [['is_doctor' => '1', 'professional_license' => '12345'], 'professional_license'],
            'especialidad larga' => [['specialty' => str_repeat('x', 121)], 'specialty'],
        ];

        foreach ($cases as $name => [$override, $field]) {
            $this->post(route('usuarios.store'), $this->userData($override))
                ->assertSessionHasErrors($field, null, 'invite');
            $this->assertDatabaseMissing('users', ['email' => 'ana.lopez@clinica.mx']);
        }
    }

    public function test_email_must_be_unique_ignoring_case(): void
    {
        $this->actingAsAdmin();

        $this->post(route('usuarios.store'), $this->userData(['email' => 'DOCTOR@Dental.test']))
            ->assertSessionHasErrors('email', null, 'invite');
    }

    public function test_email_is_normalized_to_lowercase(): void
    {
        $this->actingAsAdmin();

        $this->post(route('usuarios.store'), $this->userData(['email' => '  Ana.Lopez@Clinica.MX ']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ana.lopez@clinica.mx']);
    }

    public function test_inactive_roles_cannot_be_assigned(): void
    {
        $this->actingAsAdmin();
        $role = $this->makeRole('Auxiliar');
        $role->update(['status' => 'inactive']);

        $this->post(route('usuarios.store'), $this->userData(['role_id' => $role->id]))
            ->assertSessionHasErrors('role_id', null, 'invite');
    }

    public function test_doctor_profile_is_created_with_the_user(): void
    {
        $this->actingAsAdmin();

        $this->post(route('usuarios.store'), $this->userData([
            'is_doctor' => '1', 'professional_license' => '1234567', 'specialty' => 'Endodoncia',
        ]))->assertSessionHasNoErrors();

        $member = OrganizationMember::whereHas('user', fn ($q) => $q->where('email', 'ana.lopez@clinica.mx'))->firstOrFail();
        $profile = DB::table('doctor_profiles')->where('member_id', $member->id)->first();
        $this->assertSame('1234567', $profile->professional_license);
        $this->assertSame('Endodoncia', $profile->specialty);
    }

    // ------------------------------------------------------------ edición

    public function test_editing_updates_data_and_logs_role_change(): void
    {
        $admin = $this->actingAsAdmin();
        $member = $this->makeMember('recepcion', ['full_name' => 'Karla Méndez', 'email' => 'karla@clinica.mx']);
        $doctorRole = $this->role('doctor');

        $this->put(route('usuarios.update', $member), $this->userData([
            'full_name' => 'Karla Méndez Soto', 'email' => 'karla@clinica.mx', 'role_id' => $doctorRole->id,
        ]))->assertRedirect(route('usuarios.index'))->assertSessionHas('success');

        $member->refresh();
        $this->assertSame($doctorRole->id, $member->role_id);
        $this->assertSame('Karla Méndez Soto', $member->user->full_name);

        $log = RoleChangeHistory::where('target_member_id', $member->id)->latest('created_at')->first();
        $this->assertSame('Recepción', $log->old_value['role']);
        $this->assertSame('Doctor', $log->new_value['role']);
        $this->assertSame($admin->id, $log->actor_user_id);
    }

    public function test_edit_keeps_own_email_valid_but_rejects_someone_elses(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('recepcion', ['email' => 'karla@clinica.mx']);

        $this->put(route('usuarios.update', $member), $this->userData(['email' => 'karla@clinica.mx', 'role_id' => $member->role_id]))
            ->assertSessionHasNoErrors();

        $this->put(route('usuarios.update', $member), $this->userData(['email' => 'doctor@dental.test', 'role_id' => $member->role_id]))
            ->assertSessionHasErrors('email');
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        $admin = $this->actingAsAdmin();
        $member = $admin->member;

        $this->put(route('usuarios.update', $member), $this->userData([
            'full_name' => $admin->full_name, 'email' => $admin->email, 'role_id' => $this->role('doctor')->id,
        ]))->assertSessionHas('error');

        $this->assertSame($this->role('admin_sistema')->id, $member->fresh()->role_id);
    }

    public function test_the_last_active_admin_cannot_lose_the_admin_role(): void
    {
        $this->actingAsAdmin();
        // Un segundo usuario con permisos de gestión (no admin) intenta degradar al único admin.
        $manager = $this->makeMember('recepcion');
        $manager->update(['role_id' => $this->makeRole('Gestor', ['usuarios.ver', 'usuarios.editar', 'usuarios.desactivar'])->id]);
        $adminMember = User::where('email', 'admin@dental.test')->firstOrFail()->member;

        $this->actingAs($manager->user);
        $this->put(route('usuarios.update', $adminMember), $this->userData([
            'full_name' => 'Administrador', 'email' => 'admin@dental.test', 'role_id' => $this->role('doctor')->id,
        ]))->assertSessionHas('error', 'Debe existir al menos un administrador activo en la clínica.');

        $this->post(route('usuarios.estado', $adminMember), ['active' => 0])
            ->assertSessionHas('error', 'Debe existir al menos un administrador activo en la clínica.');

        $this->assertTrue($adminMember->fresh()->is_active);
    }

    public function test_signature_upload_is_validated_stored_privately_and_removable(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('doctor');
        $base = $this->userData(['full_name' => $member->user->full_name, 'email' => $member->user->email, 'role_id' => $member->role_id,
            'is_doctor' => '1', 'professional_license' => '7654321']);

        // Archivos no válidos
        $this->put(route('usuarios.update', $member), $base + ['signature' => UploadedFile::fake()->create('firma.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('signature');
        $this->put(route('usuarios.update', $member), $base + ['signature' => UploadedFile::fake()->image('firma.png', 100, 50)->size(2048)])
            ->assertSessionHasErrors('signature');
        $this->put(route('usuarios.update', $member), $base + ['signature' => UploadedFile::fake()->image('firma.png', 3000, 200)])
            ->assertSessionHasErrors('signature');

        // Archivo válido
        $this->put(route('usuarios.update', $member), $base + ['signature' => UploadedFile::fake()->image('firma.png', 300, 100)])
            ->assertSessionHasNoErrors();

        $key = DoctorProfile::where('member_id', $member->id)->value('signature_image_storage_key');
        $this->assertNotNull($key);
        Storage::disk('local')->assertExists($key);
        $this->get(route('usuarios.firma', $member))->assertOk();

        // Quitarla
        $this->put(route('usuarios.update', $member), $base + ['remove_signature' => '1'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($key);
        $this->get(route('usuarios.firma', $member))->assertNotFound();
    }

    public function test_unchecking_doctor_removes_the_profile(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('doctor');
        DB::table('doctor_profiles')->insert(['member_id' => $member->id, 'organization_id' => $member->organization_id, 'professional_license' => '1234567']);

        $this->put(route('usuarios.update', $member), $this->userData([
            'full_name' => $member->user->full_name, 'email' => $member->user->email, 'role_id' => $member->role_id,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('doctor_profiles', ['member_id' => $member->id]);
    }

    // -------------------------------------------------------- estado / acceso

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('recepcion');

        $this->post(route('usuarios.estado', $member), ['active' => 0])->assertSessionHas('success');
        $this->assertFalse($member->fresh()->is_active);
        $this->assertFalse($member->user->fresh()->is_active);

        $this->post(route('usuarios.estado', $member), ['active' => 1])->assertSessionHas('success');
        $this->assertTrue($member->fresh()->is_active);
        $this->assertTrue($member->user->fresh()->is_active);
    }

    public function test_deactivating_revokes_registered_sessions_and_blocks_access(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('recepcion');
        DB::table('user_sessions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $member->user_id,
            'organization_id' => $member->organization_id, 'expires_at' => now()->addDay(),
        ]);

        $this->post(route('usuarios.estado', $member), ['active' => 0]);

        $this->assertNotNull(DB::table('user_sessions')->where('user_id', $member->user_id)->value('revoked_at'));

        // Con la sesión de ese usuario, la siguiente petición lo saca.
        $this->actingAs($member->user->fresh());
        $this->get(route('usuarios.index'))->assertRedirect();
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $admin = $this->actingAsAdmin();

        $this->post(route('usuarios.estado', $admin->member), ['active' => 0])
            ->assertSessionHas('error', 'No puedes desactivar tu propia cuenta.');
        $this->assertTrue($admin->member->fresh()->is_active);
    }

    public function test_estado_requires_a_boolean(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember('recepcion');

        $this->post(route('usuarios.estado', $member), ['active' => 'tal vez'])->assertSessionHasErrors('active');
        $this->post(route('usuarios.estado', $member), [])->assertSessionHasErrors('active');
    }

    public function test_a_user_with_a_pending_invitation_cannot_be_activated_manually(): void
    {
        $this->actingAsAdmin();
        $this->post(route('usuarios.store'), $this->userData());
        $member = OrganizationMember::whereHas('user', fn ($q) => $q->where('email', 'ana.lopez@clinica.mx'))->firstOrFail();

        $this->post(route('usuarios.estado', $member), ['active' => 1])
            ->assertSessionHas('error');
        $this->assertFalse($member->fresh()->is_active);
    }

    public function test_a_new_access_link_replaces_and_revokes_the_previous_one(): void
    {
        $this->actingAsAdmin();
        $this->post(route('usuarios.store'), $this->userData());
        $first = basename(session('access_link')['url']);
        $member = OrganizationMember::whereHas('user', fn ($q) => $q->where('email', 'ana.lopez@clinica.mx'))->firstOrFail();

        $this->post(route('usuarios.acceso', $member))->assertSessionHas('access_link');
        $second = basename(session('access_link')['url']);

        $this->assertNotSame($first, $second);
        auth()->logout();
        $this->get(route('invitations.accept', $first))->assertSee('Enlace no válido');
        $this->get(route('invitations.accept', $second))->assertSee('Guardar y entrar');
        $this->assertSame(1, UserInvitation::pending()->where('email', 'ana.lopez@clinica.mx')->count());
    }

    public function test_pending_invitation_can_be_revoked(): void
    {
        $this->actingAsAdmin();
        $this->post(route('usuarios.store'), $this->userData());
        $token = basename(session('access_link')['url']);
        $member = OrganizationMember::whereHas('user', fn ($q) => $q->where('email', 'ana.lopez@clinica.mx'))->firstOrFail();

        $this->delete(route('usuarios.invitacion.revocar', $member))->assertSessionHas('success');

        auth()->logout();
        $this->get(route('invitations.accept', $token))->assertSee('Enlace no válido');
    }

    public function test_edit_page_renders_for_active_pending_and_inactive_users(): void
    {
        $this->actingAsAdmin();
        $this->get(route('usuarios.edit', $this->makeMember('doctor')))->assertOk()->assertSee('Desactivar usuario');
        $this->get(route('usuarios.edit', $this->makeMember('doctor', [], false)))->assertOk()->assertSee('Activar usuario');

        $this->post(route('usuarios.store'), $this->userData());
        $pending = OrganizationMember::whereHas('user', fn ($q) => $q->where('email', 'ana.lopez@clinica.mx'))->firstOrFail();
        $this->get(route('usuarios.edit', $pending))->assertOk()->assertSee('Invitación pendiente');
    }
}

<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Equipo demo de la clínica (Figma: Usuarios y Roles) y dos roles personalizados.
 * Idempotente: reconoce a cada persona por su correo y a cada rol por su código.
 * Todos entran con la contraseña local "password" (solo para desarrollo).
 *
 * Nota: DemoSeeder aún no lo invoca; ejecútalo con
 *   php artisan db:seed --class=DemoUsuariosSeeder
 */
class DemoUsuariosSeeder extends Seeder
{
    public function run(): void
    {
        Organization::flushCurrent();

        $branchId = Branch::query()->where('is_main', true)->value('id') ?? Branch::query()->value('id');
        if (! $branchId) {
            $this->command?->warn('Corre BaseSeeder antes de DemoUsuariosSeeder.');

            return;
        }

        $roles = [
            'admin_sistema' => Role::query()->where('code', 'admin_sistema')->firstOrFail(),
            'doctor' => Role::query()->where('code', 'doctor')->firstOrFail(),
            'recepcion' => Role::query()->where('code', 'recepcion')->firstOrFail(),
        ];

        $jefe = $this->customRole('jefe_de_odontologia', 'Jefe de odontología', 'Odontólogo con reportes, inventario y finanzas.', 'active', [
            'dashboard.ver', 'agenda.ver', 'agenda.crear', 'agenda.editar', 'pacientes.ver', 'pacientes.crear', 'pacientes.editar',
            'expediente.ver', 'expediente.crear', 'expediente.editar', 'expediente.firmar_notas',
            'recetas.ver', 'recetas.crear', 'recetas.emitir_recetas', 'presupuestos.ver', 'presupuestos.crear',
            'inventario.ver', 'finanzas.ver', 'finanzas.exportar',
        ]);
        $this->customRole('auxiliar_de_inventario', 'Auxiliar de inventario', 'Solo inventario.', 'inactive', ['inventario.ver', 'inventario.crear', 'inventario.editar']);

        $team = [
            ['Dra. Mariana Reyes',   'mariana@dentalpro.mx', $roles['admin_sistema'], true,  '4129087', 'Odontología general'],
            ['Dr. Andrés Cervantes', 'andres@dentalpro.mx',  $roles['doctor'],        true,  '9876543', 'Endodoncia'],
            ['Dra. Lucía Fonseca',   'lucia@dentalpro.mx',   $jefe,                   true,  '5567890', 'Ortodoncia'],
            ['Karla Méndez',         'recepcion@dentalpro.mx', $roles['recepcion'],   true,  null,      null],
            ['Dr. Hugo Beltrán',     'hugo@dentalpro.mx',    $roles['doctor'],        false, '3344556', 'Periodoncia'],
        ];

        foreach ($team as [$name, $email, $role, $active, $license, $specialty]) {
            $member = $this->member($name, $email, $role, $active, $branchId);

            if ($license) {
                DB::table('doctor_profiles')->updateOrInsert(
                    ['member_id' => $member->id],
                    ['organization_id' => $member->organization_id, 'professional_license' => $license, 'specialty' => $specialty]
                );
            }
        }

        // Una invitación pendiente (el enlace en claro no existe: solo el hash).
        $pending = $this->member('Valeria Núñez', 'valeria@dentalpro.mx', $roles['recepcion'], false, $branchId, withPassword: false);
        if (! UserInvitation::pending()->where('email', 'valeria@dentalpro.mx')->exists()) {
            UserInvitation::create([
                'email' => 'valeria@dentalpro.mx',
                'role_id' => $roles['recepcion']->id,
                'token_hash' => hash('sha256', Str::random(48)),
                'expires_at' => now()->addDays(7),
            ]);
        }
        unset($pending);
    }

    private function member(string $name, string $email, Role $role, bool $active, string $branchId, bool $withPassword = true): OrganizationMember
    {
        // Si otro seeder demo (Agenda, BaseSeeder) ya creó a esta persona, se reutiliza:
        // evita doctores duplicados con distinto correo y no cambia su rol ni su correo.
        $existing = User::query()->where('email', '!=', $email)->where('full_name', $name)->first();
        if ($existing && ! User::query()->where('email', $email)->exists()) {
            $member = OrganizationMember::query()->where('user_id', $existing->id)->first();
            if ($member) {
                DB::table('member_branches')->insertOrIgnore([
                    'organization_id' => $member->organization_id,
                    'member_id' => $member->id,
                    'branch_id' => $branchId,
                ]);

                return $member;
            }
        }

        $user = User::query()->where('email', $email)->first() ?? new User;
        $user->forceFill([
            'full_name' => $name,
            'email' => $email,
            'is_active' => $active,
            'email_verified_at' => $withPassword ? now() : null,
        ]);
        if ($withPassword && blank($user->password)) {
            $user->password = 'password';
        }
        $user->save();

        $member = OrganizationMember::query()->firstOrNew(['user_id' => $user->id]);
        $member->role_id = $role->id;
        $member->is_active = $active;
        $member->save();

        DB::table('member_branches')->insertOrIgnore([
            'organization_id' => $member->organization_id,
            'member_id' => $member->id,
            'branch_id' => $branchId,
        ]);

        return $member;
    }

    /** @param  array<int, string>  $keys  "modulo.accion" */
    private function customRole(string $code, string $name, string $description, string $status, array $keys): Role
    {
        $role = Role::query()->firstOrNew(['code' => $code]);
        $created = ! $role->exists;
        $role->fill(['name' => $name, 'description' => $description, 'status' => $status, 'is_system_admin' => false])->save();

        if ($created) {
            $ids = Permission::query()->get()
                ->filter(fn ($p) => in_array($p->module.'.'.$p->action, $keys, true))
                ->pluck('id')->all();
            $role->syncPermissions($ids);
        }

        return $role;
    }
}

<?php

namespace Tests\Feature\Usuarios;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas de Usuarios y Roles. Limpia la caché estática de la
 * organización (el id cambia en cada prueba porque se resiembra) y usa un
 * disco falso para las firmas.
 */
abstract class UsuariosTestCase extends TestCase
{
    protected function setUp(): void
    {
        Organization::flushCurrent();
        parent::setUp();
        Organization::flushCurrent();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Organization::flushCurrent();
        parent::tearDown();
    }

    protected function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    /** Crea usuario + membresía con el rol indicado (por código). */
    protected function makeMember(string $roleCode = 'recepcion', array $userAttrs = [], bool $active = true): OrganizationMember
    {
        $user = User::factory()->create(array_merge(['is_active' => $active], $userAttrs));

        return OrganizationMember::create([
            'user_id' => $user->id,
            'role_id' => $this->role($roleCode)->id,
            'is_active' => $active,
        ]);
    }

    /** Rol personalizado con los permisos "modulo.accion" indicados. */
    protected function makeRole(string $name, array $keys = []): Role
    {
        $role = Role::create([
            'code' => str($name)->slug('_')->toString(),
            'name' => $name,
            'status' => 'active',
            'is_system_admin' => false,
        ]);

        $ids = DB::table('permissions')
            ->whereIn(DB::raw("CONCAT(module, '.', action)"), $keys)
            ->pluck('id')->all();
        $role->syncPermissions($ids);

        return $role;
    }

    /** Datos válidos para invitar a un usuario. */
    protected function userData(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Dra. Ana López Ruiz',
            'email' => 'ana.lopez@clinica.mx',
            'role_id' => $this->role('doctor')->id,
            'branch_ids' => [Branch::query()->value('id')],
        ], $overrides);
    }

    protected function permissionId(string $module, string $action): string
    {
        return (string) DB::table('permissions')->where('module', $module)->where('action', $action)->value('id');
    }
}

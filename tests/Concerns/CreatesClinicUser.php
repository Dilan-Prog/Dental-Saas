<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea usuarios con membresia en el consultorio usando solo la tabla
 * (sin depender de los modelos ni de los seeders de otros modulos).
 */
trait CreatesClinicUser
{
    protected function clinicOrganizationId(): string
    {
        $id = DB::table('organizations')->value('id');

        if ($id) {
            return $id;
        }

        $id = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id' => $id,
            'commercial_name' => 'Consultorio de prueba',
        ]);

        return $id;
    }

    protected function clinicRoleId(string $code = 'recepcion', bool $isSystemAdmin = false): string
    {
        $org = $this->clinicOrganizationId();
        $id = DB::table('roles')->where('organization_id', $org)->where('code', $code)->value('id');

        if ($id) {
            return $id;
        }

        $id = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $id,
            'organization_id' => $org,
            'code' => $code,
            'name' => ucfirst($code),
            'is_system_admin' => $isSystemAdmin,
            'status' => 'active',
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes  atributos del usuario
     */
    protected function createClinicUser(array $attributes = [], string $roleCode = 'recepcion', bool $memberActive = true): User
    {
        $user = User::factory()->create($attributes);

        DB::table('organization_members')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->clinicOrganizationId(),
            'user_id' => $user->id,
            'role_id' => $this->clinicRoleId($roleCode, $roleCode === 'admin_sistema'),
            'is_active' => $memberActive,
        ]);

        return $user;
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Datos base de la clínica (idempotente, sin modelos Eloquent):
 * organización, sucursal, roles, permisos, folios, impuestos y usuarios locales.
 */
class BaseSeeder extends Seeder
{
    public const MODULES = [
        'dashboard', 'agenda', 'pacientes', 'expediente', 'recetas', 'tratamientos',
        'presupuestos', 'facturacion', 'finanzas', 'inventario', 'configuracion',
        'usuarios', 'roles', 'documentos',
    ];

    public const ACTIONS = ['ver', 'crear', 'editar', 'desactivar', 'exportar'];

    public const SPECIAL = [
        'expediente' => ['firmar_notas'],
        'recetas' => ['emitir_recetas'],
        'facturacion' => ['cancelar_cfdi', 'registrar_pagos'],
        'finanzas' => ['cerrar_caja'],
    ];

    /** Permisos por rol no administrador: módulo => acciones. */
    public const ROLE_GRANTS = [
        'doctor' => [
            'dashboard' => ['ver'],
            'agenda' => ['ver', 'crear', 'editar'],
            'pacientes' => ['ver', 'crear', 'editar'],
            'expediente' => ['ver', 'crear', 'editar', 'firmar_notas'],
            'recetas' => ['ver', 'crear', 'editar', 'emitir_recetas'],
            'tratamientos' => ['ver', 'crear', 'editar'],
            'presupuestos' => ['ver', 'crear', 'editar'],
            'documentos' => ['ver', 'crear'],
        ],
        'recepcion' => [
            'dashboard' => ['ver'],
            'agenda' => ['ver', 'crear', 'editar'],
            'pacientes' => ['ver', 'crear', 'editar'],
            'presupuestos' => ['ver', 'crear', 'editar'],
            'facturacion' => ['ver', 'registrar_pagos'],
            'inventario' => ['ver'],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $orgId = $this->organization();
            $branchId = $this->branch($orgId);
            $roles = $this->roles($orgId);
            $this->permissions($orgId, $roles);
            $this->folios($orgId);
            $this->taxProfiles($orgId);
            $this->users($orgId, $branchId, $roles);
        });
    }

    private function organization(): string
    {
        $id = DB::table('organizations')->orderBy('created_at')->value('id');

        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('organizations')->insert([
                'id' => $id,
                'commercial_name' => 'Clínica Dental',
                'legal_name' => null,
                'currency' => 'MXN',
                'timezone' => 'America/Mexico_City',
                'status' => 'active',
            ]);
        }

        DB::table('organization_fiscal_settings')->insertOrIgnore([
            'organization_id' => $id,
            'tax_regime_code' => '612',
            'fiscal_postal_code' => '06000',
            'default_series' => 'A',
        ]);

        return $id;
    }

    private function branch(string $orgId): string
    {
        $row = DB::table('branches')->where('organization_id', $orgId)->where('name', 'Clínica Centro')->first();

        if ($row) {
            return $row->id;
        }

        $id = (string) Str::uuid();
        DB::table('branches')->insert([
            'id' => $id,
            'organization_id' => $orgId,
            'name' => 'Clínica Centro',
            'status' => 'active',
            'is_main' => true,
        ]);

        return $id;
    }

    /** @return array<string,string> code => id */
    private function roles(string $orgId): array
    {
        $defs = [
            'admin_sistema' => ['Administrador', 'Propietaria / Administrador', true],
            'doctor' => ['Doctor', 'Doctor / Odontólogo', false],
            'recepcion' => ['Recepción', 'Secretaria / Asistente', false],
        ];

        $ids = [];
        foreach ($defs as $code => [$name, $description, $isAdmin]) {
            $id = DB::table('roles')->where('organization_id', $orgId)->where('code', $code)->value('id');

            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('roles')->insert([
                    'id' => $id,
                    'organization_id' => $orgId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'is_system_admin' => $isAdmin,
                    'status' => 'active',
                ]);
            }
            $ids[$code] = $id;
        }

        return $ids;
    }

    private function permissions(string $orgId, array $roles): void
    {
        $all = [];
        foreach (self::MODULES as $module) {
            foreach (array_merge(self::ACTIONS, self::SPECIAL[$module] ?? []) as $action) {
                $all["{$module}.{$action}"] = [$module, $action];
            }
        }

        $permIds = [];
        foreach ($all as $key => [$module, $action]) {
            $id = DB::table('permissions')->where('module', $module)->where('action', $action)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $id,
                    'module' => $module,
                    'action' => $action,
                    'description' => ucfirst(str_replace('_', ' ', $action)) . ' · ' . $module,
                ]);
            }
            $permIds[$key] = $id;
        }

        // Admin: todos los permisos (además de is_system_admin).
        $grants = ['admin_sistema' => array_keys($all)];
        foreach (self::ROLE_GRANTS as $role => $modules) {
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action) {
                    $grants[$role][] = "{$module}.{$action}";
                }
            }
        }

        foreach ($grants as $role => $keys) {
            foreach ($keys as $key) {
                DB::table('role_permissions')->insertOrIgnore([
                    'organization_id' => $orgId,
                    'role_id' => $roles[$role],
                    'permission_id' => $permIds[$key],
                ]);
            }
        }
    }

    private function folios(string $orgId): void
    {
        foreach (['patient' => 'PAC-', 'prescription' => 'RX-', 'quote' => 'PRE-', 'invoice' => 'F-'] as $type => $prefix) {
            $exists = DB::table('folio_sequences')
                ->where('organization_id', $orgId)->where('document_type', $type)->where('series', '')->exists();

            if (! $exists) {
                DB::table('folio_sequences')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'document_type' => $type,
                    'series' => '',
                    'prefix' => $prefix,
                    'last_value' => 0,
                ]);
            }
        }
    }

    private function taxProfiles(string $orgId): void
    {
        $defs = [
            'IVA 16%' => ['002', '0.160000', false],
            'Exento' => ['002', '0.000000', true],
        ];

        foreach ($defs as $name => [$code, $rate, $exempt]) {
            if (! DB::table('tax_profiles')->where('organization_id', $orgId)->where('name', $name)->exists()) {
                DB::table('tax_profiles')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'name' => $name,
                    'tax_code' => $code,
                    'rate' => $rate,
                    'is_exempt' => $exempt,
                    'status' => 'active',
                ]);
            }
        }
    }

    private function users(string $orgId, string $branchId, array $roles): void
    {
        $defs = [
            ['admin@dental.test', 'Administrador', 'admin_sistema', null],
            ['doctor@dental.test', 'Dra. Mariana Reyes', 'doctor', ['1234567', 'Odontología general']],
            ['recepcion@dental.test', 'Recepción Clínica', 'recepcion', null],
        ];

        $hasPassword = Schema::hasColumn('users', 'password');
        $hasVerified = Schema::hasColumn('users', 'email_verified_at');

        foreach ($defs as [$email, $fullName, $role, $doctor]) {
            $userId = DB::table('users')->where('email', $email)->value('id');

            if (! $userId) {
                $userId = (string) Str::uuid();
                $row = ['id' => $userId, 'full_name' => $fullName, 'email' => $email, 'is_active' => true];
                if ($hasPassword) {
                    $row['password'] = Hash::make('password');
                }
                if ($hasVerified) {
                    $row['email_verified_at'] = now();
                }
                DB::table('users')->insert($row);
            }

            $memberId = DB::table('organization_members')
                ->where('organization_id', $orgId)->where('user_id', $userId)->value('id');

            if (! $memberId) {
                $memberId = (string) Str::uuid();
                DB::table('organization_members')->insert([
                    'id' => $memberId,
                    'organization_id' => $orgId,
                    'user_id' => $userId,
                    'role_id' => $roles[$role],
                    'is_active' => true,
                ]);
            }

            DB::table('member_branches')->insertOrIgnore([
                'organization_id' => $orgId,
                'member_id' => $memberId,
                'branch_id' => $branchId,
            ]);

            if ($doctor) {
                DB::table('doctor_profiles')->insertOrIgnore([
                    'member_id' => $memberId,
                    'organization_id' => $orgId,
                    'professional_license' => $doctor[0],
                    'specialty' => $doctor[1],
                ]);
            }
        }

        // La sucursal principal la gerencia el administrador.
        $adminId = DB::table('users')->where('email', 'admin@dental.test')->value('id');
        DB::table('branches')->where('id', $branchId)->whereNull('manager_user_id')->update(['manager_user_id' => $adminId]);
    }
}

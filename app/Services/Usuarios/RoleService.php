<?php

namespace App\Services\Usuarios;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleChangeHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reglas de negocio de roles y matriz de permisos.
 * Todo cambio relevante queda en role_change_history (quién, qué, antes/después).
 */
class RoleService
{
    public function __construct(private readonly UserAccessService $access)
    {
    }

    /** Etiquetas de módulos para la matriz (orden de presentación). */
    public const MODULE_LABELS = [
        'dashboard' => 'Dashboard',
        'agenda' => 'Agenda',
        'pacientes' => 'Pacientes',
        'expediente' => 'Expedientes',
        'datos_clinicos' => 'Datos clínicos',
        'recetas' => 'Recetas',
        'tratamientos' => 'Tratamientos',
        'presupuestos' => 'Presupuestos',
        'facturacion' => 'Facturación',
        'finanzas' => 'Finanzas',
        'inventario' => 'Inventario',
        'documentos' => 'Documentos',
        'configuracion' => 'Configuración',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles',
    ];

    public const STANDARD_ACTIONS = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'desactivar' => 'Desactivar',
        'exportar' => 'Exportar',
    ];

    public const SPECIAL_ACTIONS = [
        'firmar_notas' => 'Firmar notas',
        'emitir_recetas' => 'Emitir recetas',
        'cancelar_cfdi' => 'Cancelar CFDI',
        'registrar_pagos' => 'Registrar pagos',
        'cerrar_caja' => 'Cerrar caja',
    ];

    // ------------------------------------------------------------------ roles

    public function create(string $name, ?string $description, User $actor): Role
    {
        return DB::transaction(function () use ($name, $description, $actor) {
            $role = Role::create([
                'code' => $this->uniqueCode($name),
                'name' => $name,
                'description' => $description,
                'is_system_admin' => false,
                'status' => 'active',
            ]);

            $this->log('role_created', $role, $actor, null, ['name' => $name, 'description' => $description]);

            return $role;
        });
    }

    public function update(Role $role, string $name, ?string $description, User $actor): Role
    {
        $this->guardNotSystem($role);

        $old = ['name' => $role->name, 'description' => $role->description];
        $new = ['name' => $name, 'description' => $description];

        if ($old === $new) {
            return $role;
        }

        DB::transaction(function () use ($role, $new, $old, $actor) {
            $role->update($new);
            $this->log('role_updated', $role, $actor, $old, $new);
        });

        return $role->refresh();
    }

    /**
     * Reemplaza los permisos del rol. Registra el diff y cierra las sesiones
     * registradas de los usuarios con ese rol (los permisos se leen de la BD en
     * cada petición, así que aplican de inmediato).
     *
     * @param  array<int, string>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actor): bool
    {
        $this->guardNotSystem($role);

        $permissionIds = array_values(array_unique($permissionIds));
        $this->assertConsistent($permissionIds);

        $before = $this->grantedKeys($role);
        $keysById = Permission::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->module.'.'.$p->action]);
        $after = collect($permissionIds)->map(fn ($id) => $keysById[$id])->sort()->values()->all();

        if ($before === $after) {
            return false;
        }

        DB::transaction(function () use ($role, $permissionIds, $before, $after, $actor) {
            $role->syncPermissions($permissionIds);

            $this->log('role_permissions_changed', $role, $actor,
                ['permissions' => $before],
                [
                    'permissions' => $after,
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ]
            );

            $this->revokeSessions($role, 'permissions_changed');
        });

        return true;
    }

    /**
     * Desactiva un rol reasignando a sus usuarios a otro rol activo.
     */
    public function deactivate(Role $role, ?string $replacementId, User $actor): int
    {
        $this->guardNotSystem($role);

        if ($role->status->value !== 'active') {
            throw new AccessException('El rol ya está inactivo.');
        }

        $members = $role->members()->with('user')->get();
        $replacement = null;

        if ($members->isNotEmpty()) {
            $replacement = Role::query()->active()->where('id', '!=', $role->id)->find($replacementId);
            if (! $replacement) {
                throw new AccessException('Elige un rol activo al que pasarán los usuarios de este rol.');
            }
            foreach ($members as $member) {
                if ($member->user_id === $actor->id) {
                    throw new AccessException('No puedes desactivar el rol que tú mismo tienes asignado.');
                }
            }
        }

        try {
            DB::transaction(function () use ($role, $members, $replacement, $actor) {
                foreach ($members as $member) {
                    $member->update(['role_id' => $replacement->id]);
                    $this->access->recordRoleAssignment($member, $role, $replacement, $actor);
                }

                $role->update(['status' => 'inactive']);
                $this->log('role_deactivated', $role, $actor,
                    ['status' => 'active'],
                    ['status' => 'inactive', 'reassigned_to' => $replacement?->name, 'users' => $members->count()]
                );
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }

        return $members->count();
    }

    public function activate(Role $role, User $actor): void
    {
        $this->guardNotSystem($role);

        if ($role->status->value === 'active') {
            return;
        }

        DB::transaction(function () use ($role, $actor) {
            $role->update(['status' => 'active']);
            $this->log('role_activated', $role, $actor, ['status' => 'inactive'], ['status' => 'active']);
        });
    }

    /**
     * Asigna el rol a los miembros indicados (los saca de su rol actual).
     *
     * @param  array<int, string>  $memberIds
     */
    public function assignUsers(Role $role, array $memberIds, User $actor): int
    {
        $this->guardNotSystem($role);

        if ($role->status->value !== 'active') {
            throw new AccessException('No se pueden asignar usuarios a un rol inactivo.');
        }

        $members = OrganizationMember::query()->with(['user', 'role'])->whereIn('id', $memberIds)->get();
        $moved = 0;

        try {
            DB::transaction(function () use ($role, $members, $actor, &$moved) {
                foreach ($members as $member) {
                    if ($member->role_id === $role->id) {
                        continue;
                    }
                    if ($member->user_id === $actor->id) {
                        throw new AccessException('No puedes cambiar tu propio rol.');
                    }
                    $this->access->guardLastAdmin($member, false);

                    $old = $member->role;
                    $member->update(['role_id' => $role->id]);
                    $this->access->recordRoleAssignment($member, $old, $role, $actor);
                    $moved++;
                }
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }

        return $moved;
    }

    /** Quita el rol a un usuario (queda sin rol = sin acceso a módulos). */
    public function removeUser(Role $role, OrganizationMember $member, User $actor): void
    {
        $this->guardNotSystem($role);

        if ($member->role_id !== $role->id) {
            throw new AccessException('El usuario no pertenece a este rol.');
        }
        if ($member->user_id === $actor->id) {
            throw new AccessException('No puedes quitarte tu propio rol.');
        }

        $member->loadMissing(['user', 'role']);
        $this->access->guardLastAdmin($member, false);

        try {
            DB::transaction(function () use ($member, $role, $actor) {
                $member->update(['role_id' => null]);
                $this->access->recordRoleAssignment($member, $role, null, $actor);
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }
    }

    // ----------------------------------------------------------- consultas

    /** @return array<int, string> claves "modulo.accion" concedidas, ordenadas. */
    public function grantedKeys(Role $role): array
    {
        if ($role->is_system_admin) {
            return Permission::query()->get()->map(fn ($p) => $p->module.'.'.$p->action)->sort()->values()->all();
        }

        return DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role->id)
            ->get(['p.module', 'p.action'])
            ->map(fn ($r) => $r->module.'.'.$r->action)
            ->sort()->values()->all();
    }

    /**
     * Estructura de la matriz: filas por módulo con sus permisos disponibles.
     *
     * @return array<int, array{module:string,label:string,sub:bool,cells:array,specials:array}>
     */
    public function matrix(): array
    {
        $all = Permission::query()->get()->groupBy('module');
        $rows = [];

        $order = array_keys(self::MODULE_LABELS);
        foreach ($all->keys() as $module) {
            if (! in_array($module, $order, true)) {
                $order[] = $module;
            }
        }

        foreach ($order as $module) {
            if (! $all->has($module)) {
                continue;
            }
            $perms = $all[$module]->keyBy('action');
            $cells = [];
            foreach (self::STANDARD_ACTIONS as $action => $label) {
                $cells[$action] = $perms[$action]->id ?? null;
            }
            $specials = [];
            foreach ($perms as $action => $perm) {
                if (! isset(self::STANDARD_ACTIONS[$action])) {
                    $specials[$action] = ['id' => $perm->id, 'label' => self::SPECIAL_ACTIONS[$action] ?? Str::headline($action)];
                }
            }

            $rows[] = [
                'module' => $module,
                'label' => self::MODULE_LABELS[$module] ?? Str::headline($module),
                'sub' => $module === 'datos_clinicos',
                'cells' => $cells,
                'specials' => $specials,
            ];
        }

        return $rows;
    }

    /** Ítems del menú lateral que vería un rol (para la vista previa). */
    public function menuPreview(Role $role): array
    {
        $granted = array_flip($this->grantedKeys($role));
        $items = [];

        foreach (config('menu.sections', []) as $section) {
            foreach ($section['items'] as $item) {
                $items[] = [
                    'label' => $item['label'],
                    'visible' => $role->is_system_admin || isset($granted[($item['module'] ?? '').'.ver']),
                ];
            }
        }

        return $items;
    }

    // -------------------------------------------------------------- privado

    /** Todo permiso distinto de "ver" exige "ver" en el mismo módulo. */
    protected function assertConsistent(array $permissionIds): void
    {
        $perms = Permission::query()->whereIn('id', $permissionIds)->get();

        if ($perms->count() !== count($permissionIds)) {
            throw new AccessException('Se recibió un permiso que no existe.');
        }

        foreach ($perms->groupBy('module') as $module => $group) {
            $hasView = $group->contains('action', 'ver');
            $hasOthers = $group->contains(fn ($p) => $p->action !== 'ver');
            $viewExists = Permission::query()->where('module', $module)->where('action', 'ver')->exists();

            if ($hasOthers && ! $hasView && $viewExists) {
                $label = self::MODULE_LABELS[$module] ?? Str::headline($module);
                throw new AccessException("Para conceder permisos en «{$label}» también debes conceder «Ver».");
            }
        }
    }

    protected function guardNotSystem(Role $role): void
    {
        if ($role->is_system_admin) {
            throw new AccessException('El rol de administrador del sistema no se puede editar ni desactivar.');
        }
    }

    protected function revokeSessions(Role $role, string $reason): void
    {
        $userIds = $role->members()->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('user_sessions')
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 40, '') ?: 'rol';
        $code = $base;
        $i = 2;

        while (Role::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 40 - strlen((string) $i) - 1, '').'_'.$i++;
        }

        return $code;
    }

    protected function log(string $type, Role $role, User $actor, ?array $old, ?array $new): void
    {
        RoleChangeHistory::create([
            'organization_id' => Organization::currentId(),
            'change_type' => $type,
            'role_id' => $role->id,
            'old_value' => $old,
            'new_value' => $new,
            'actor_user_id' => $actor->id,
        ]);
    }
}

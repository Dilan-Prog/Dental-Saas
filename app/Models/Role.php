<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `roles`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system_admin
 * @property \App\Enums\RecordStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrganizationMember> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 */
class Role extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'roles';

    protected $casts = [
        'is_system_admin' => 'boolean',
        'status' => \App\Enums\RecordStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'role_id');
    }


    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withPivot('granted_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    /** Reemplaza los permisos del rol (ids de permissions). */
    public function syncPermissions(array $permissionIds): void
    {
        DB::transaction(function () use ($permissionIds) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            $now = now();
            $rows = array_map(fn ($pid) => [
                'organization_id' => $this->organization_id,
                'role_id' => $this->id,
                'permission_id' => $pid,
                'granted_at' => $now,
            ], array_values(array_unique($permissionIds)));
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        });
    }

    /** El rol administrador del sistema tiene todos los permisos. */
    public function hasPermission(string $module, string $action): bool
    {
        if ($this->is_system_admin) {
            return true;
        }

        return $this->permissions()
            ->where('permissions.module', $module)
            ->where('permissions.action', $action)
            ->exists();
    }

}

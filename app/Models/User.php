<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'auth_provider_id',
        'full_name',
        'email',
        'password',
        'avatar_storage_key',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /** Rol activo del miembro (cache por request). false = aun no consultado. */
    protected object|null|false $roleInfo = false;

    /** Set "modulo.accion" de permisos concedidos (cache por request). */
    protected ?array $permissionSet = null;

    /** Compatibilidad con Breeze, que lee $user->name. */
    public function getNameAttribute(): ?string
    {
        return $this->full_name;
    }

    /**
     * Membresia del usuario en la organizacion (un solo consultorio).
     * El alcance por organizacion lo aplica el scope global de OrganizationMember.
     */
    public function member(): HasOne
    {
        return $this->hasOne(OrganizationMember::class, 'user_id');
    }

    public function role(): HasOneThrough
    {
        return $this->hasOneThrough(
            Role::class,
            OrganizationMember::class,
            'user_id',   // organization_members.user_id
            'id',        // roles.id
            'id',        // users.id
            'role_id'    // organization_members.role_id
        );
    }

    /** true si el usuario esta activo y tiene una membresia activa. */
    public function hasActiveMembership(): bool
    {
        return (bool) $this->is_active && DB::table('organization_members')
            ->where('user_id', $this->getKey())
            ->where('is_active', true)
            ->exists();
    }

    public function isAdmin(): bool
    {
        $role = $this->activeRoleInfo();

        return $role !== null
            && ((bool) $role->is_system_admin || $role->code === 'admin_sistema');
    }

    public function hasPermission(string $module, string $action): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $role = $this->activeRoleInfo();
        if ($role === null) {
            return false;
        }

        if ($this->permissionSet === null) {
            $this->permissionSet = DB::table('role_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role->role_id)
                ->get(['p.module', 'p.action'])
                ->mapWithKeys(fn ($row) => [$row->module.'.'.$row->action => true])
                ->all();
        }

        return isset($this->permissionSet[$module.'.'.$action]);
    }

    /** Descarta el cache de rol/permisos (tras cambiar rol o permisos). */
    public function flushPermissionCache(): void
    {
        $this->roleInfo = false;
        $this->permissionSet = null;
    }

    protected function activeRoleInfo(): ?object
    {
        if ($this->roleInfo === false) {
            $this->roleInfo = DB::table('organization_members as m')
                ->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('m.user_id', $this->getKey())
                ->where('m.is_active', true)
                ->where('r.status', 'active')
                ->first(['m.role_id', 'r.code', 'r.is_system_admin']);
        }

        return $this->roleInfo;
    }
}

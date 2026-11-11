<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `role_permissions`.
 *
 * @property string $organization_id
 * @property string $role_id
 * @property string $permission_id
 * @property \Illuminate\Support\Carbon $granted_at
 *
 * @property-read \App\Models\Role|null $role
 * @property-read \App\Models\Permission|null $permission
 */
class RolePermission extends BasePivotModel
{
    use BelongsToOrganization;

    protected $table = 'role_permissions';

    protected $primaryKey = null;

    protected array $compositeKey = ['role_id', 'permission_id'];

    public $timestamps = false;

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }

}

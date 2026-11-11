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
 * Tabla `role_change_history`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $change_type
 * @property string|null $target_member_id
 * @property string|null $role_id
 * @property array|null $old_value
 * @property array|null $new_value
 * @property string|null $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\OrganizationMember|null $targetMember
 * @property-read \App\Models\Role|null $role
 * @property-read \App\Models\User|null $actor
 */
class RoleChangeHistory extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'role_change_history';

    public const UPDATED_AT = null;

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public function targetMember(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'target_member_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

}

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
 * Tabla `audit_log`.
 *
 * @property int $id
 * @property string $organization_id
 * @property string|null $actor_user_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $action
 * @property array|null $changed_fields
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\User|null $actor
 */
class AuditLog extends BasePivotModel
{
    use BelongsToOrganization;

    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $casts = [
        'changed_fields' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public $incrementing = true;

    protected $keyType = 'int';

}

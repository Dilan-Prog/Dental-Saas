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
 * Tabla `notifications`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_type
 * @property string $title
 * @property string|null $body
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string $dedup_key
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\NotificationRecipient> $recipients
 */
class AppNotification extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }

}

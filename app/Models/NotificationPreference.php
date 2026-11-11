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
 * Tabla `notification_preferences`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_type
 * @property \App\Enums\NotificationChannel $channel
 * @property bool $is_enabled
 * @property int|null $lead_time_minutes
 * @property string|null $recipient_role_id
 *
 * @property-read \App\Models\Role|null $recipientRole
 */
class NotificationPreference extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'notification_preferences';

    public $timestamps = false;

    protected $casts = [
        'channel' => \App\Enums\NotificationChannel::class,
        'is_enabled' => 'boolean',
        'lead_time_minutes' => 'integer',
    ];

    public function recipientRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'recipient_role_id');
    }

}

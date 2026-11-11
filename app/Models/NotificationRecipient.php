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
 * Tabla `notification_recipients`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $notification_id
 * @property string|null $user_id
 * @property string|null $patient_id
 * @property \App\Enums\NotificationChannel $channel
 * @property \Illuminate\Support\Carbon $scheduled_for
 * @property \Illuminate\Support\Carbon|null $read_at
 *
 * @property-read \App\Models\AppNotification|null $notification
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Patient|null $patient
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DeliveryAttempt> $deliveryAttempts
 */
class NotificationRecipient extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'notification_recipients';

    public $timestamps = false;

    protected $casts = [
        'channel' => \App\Enums\NotificationChannel::class,
        'scheduled_for' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class, 'recipient_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

}

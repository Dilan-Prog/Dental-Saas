<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `outbox_events`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array $payload
 * @property \App\Enums\OutboxStatus $status
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DeliveryAttempt> $deliveryAttempts
 */
class OutboxEvent extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'outbox_events';

    public const UPDATED_AT = null;

    protected $casts = [
        'payload' => 'array',
        'status' => \App\Enums\OutboxStatus::class,
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
        'created_at' => 'datetime',
    ];

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class, 'outbox_event_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OutboxStatus::Pendiente->value)->where('available_at', '<=', now());
    }

}

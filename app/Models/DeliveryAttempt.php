<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `delivery_attempts`.
 *
 * @property string $id
 * @property string|null $outbox_event_id
 * @property string|null $recipient_id
 * @property \App\Enums\NotificationChannel $channel
 * @property \App\Enums\DeliveryStatus $status
 * @property string|null $provider_message_id
 * @property string|null $error_detail
 * @property \Illuminate\Support\Carbon $attempted_at
 *
 * @property-read \App\Models\OutboxEvent|null $outboxEvent
 * @property-read \App\Models\NotificationRecipient|null $recipient
 */
class DeliveryAttempt extends BaseModel
{
    protected $table = 'delivery_attempts';

    public $timestamps = false;

    protected $casts = [
        'channel' => \App\Enums\NotificationChannel::class,
        'status' => \App\Enums\DeliveryStatus::class,
        'attempted_at' => 'datetime',
    ];

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

}

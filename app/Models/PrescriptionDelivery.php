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
 * Tabla `prescription_deliveries`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $prescription_id
 * @property string $channel
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property string|null $actor_user_id
 * @property string|null $outbox_event_id
 *
 * @property-read \App\Models\Prescription|null $prescription
 * @property-read \App\Models\User|null $actor
 * @property-read \App\Models\OutboxEvent|null $outboxEvent
 */
class PrescriptionDelivery extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'prescription_deliveries';

    public $timestamps = false;

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

}

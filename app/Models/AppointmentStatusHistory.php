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
 * Tabla `appointment_status_history`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $appointment_id
 * @property \App\Enums\AppointmentStatus|null $from_status
 * @property \App\Enums\AppointmentStatus $to_status
 * @property \Illuminate\Support\Carbon|null $old_starts_at
 * @property \Illuminate\Support\Carbon|null $new_starts_at
 * @property string|null $reason
 * @property string|null $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Appointment|null $appointment
 * @property-read \App\Models\User|null $actor
 */
class AppointmentStatusHistory extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'appointment_status_history';

    public const UPDATED_AT = null;

    protected $casts = [
        'from_status' => \App\Enums\AppointmentStatus::class,
        'to_status' => \App\Enums\AppointmentStatus::class,
        'old_starts_at' => 'datetime',
        'new_starts_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

}

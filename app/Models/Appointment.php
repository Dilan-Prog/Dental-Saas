<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `appointments`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $patient_id
 * @property string $doctor_member_id
 * @property string|null $treatment_id
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property \App\Enums\AppointmentStatus $status
 * @property string|null $notes
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\OrganizationMember|null $doctor
 * @property-read \App\Models\Treatment|null $treatment
 * @property-read \App\Models\User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AppointmentStatusHistory> $statusHistory
 * @property-read \App\Models\ClinicalEncounter|null $encounter
 */
class Appointment extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'appointments';

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'status' => \App\Enums\AppointmentStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'doctor_member_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class, 'appointment_id')->orderBy('created_at');
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(ClinicalEncounter::class, 'appointment_id');
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Citas que ocupan agenda (pendiente, confirmada, en curso). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::blockingValues());
    }

    public function scopeOnDate(Builder $query, $date): Builder
    {
        $d = Carbon::parse($date);

        return $query->whereBetween('starts_at', [$d->copy()->startOfDay(), $d->copy()->endOfDay()]);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->where('starts_at', '<', Carbon::parse($to))->where('ends_at', '>', Carbon::parse($from));
    }

    public function canTransitionTo(AppointmentStatus $to): bool
    {
        return in_array($to, $this->status->allowedTransitions(), true);
    }

}

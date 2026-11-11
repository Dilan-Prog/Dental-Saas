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
 * Tabla `organization_members`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $role_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Role|null $role
 * @property-read \App\Models\DoctorProfile|null $doctorProfile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $doctorAppointments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Patient> $assignedPatients
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Branch> $branches
 */
class OrganizationMember extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'organization_members';

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class, 'member_id');
    }

    public function doctorAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_member_id');
    }

    public function assignedPatients(): HasMany
    {
        return $this->hasMany(Patient::class, 'assigned_doctor_member_id');
    }


    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'member_branches', 'member_id', 'branch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getNameAttribute(): ?string
    {
        return $this->user?->full_name;
    }

    public function isDoctor(): bool
    {
        return $this->role?->code === 'doctor' || $this->doctorProfile !== null;
    }

}

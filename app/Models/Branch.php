<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `branches`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $address_line
 * @property string|null $phone
 * @property string|null $manager_user_id
 * @property \App\Enums\RecordStatus $status
 * @property bool $is_main
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property string|null $main_org_key
 *
 * @property-read \App\Models\User|null $manager
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CashRegister> $cashRegisters
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $appointments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrganizationMember> $members
 */
class Branch extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'branches';

    protected $hidden = ['main_org_key'];

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
        'is_main' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'branch_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'branch_id');
    }


    public function members(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationMember::class, 'member_branches', 'branch_id', 'member_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('is_main', true);
    }

}

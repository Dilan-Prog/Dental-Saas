<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `organizations`.
 *
 * @property string $id
 * @property string $commercial_name
 * @property string|null $legal_name
 * @property string|null $rfc
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line
 * @property string|null $address_postal_code
 * @property string|null $logo_storage_key
 * @property string $currency
 * @property string $timezone
 * @property \App\Enums\RecordStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\OrganizationFiscalSetting|null $fiscalSettings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Branch> $branches
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrganizationMember> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Patient> $patients
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FolioSequence> $folioSequences
 */
class Organization extends BaseModel
{
    protected $table = 'organizations';

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function fiscalSettings(): HasOne
    {
        return $this->hasOne(OrganizationFiscalSetting::class, 'organization_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'organization_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'organization_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'organization_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'organization_id');
    }

    public function folioSequences(): HasMany
    {
        return $this->hasMany(FolioSequence::class, 'organization_id');
    }

    protected static ?string $currentId = null;

    /** Id de la organización (consultorio) vigente: la primera registrada. */
    public static function currentId(): ?string
    {
        return static::$currentId ??= static::query()->orderBy('created_at')->orderBy('id')->value('id');
    }

    public static function current(): ?self
    {
        $id = static::currentId();

        return $id ? static::query()->find($id) : null;
    }

    /** Olvida la organización en memoria (tests, seeders). */
    public static function flushCurrent(): void
    {
        static::$currentId = null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

}

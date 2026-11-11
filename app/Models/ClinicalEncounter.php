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
 * Tabla `clinical_encounters`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $medical_record_id
 * @property string|null $appointment_id
 * @property string $branch_id
 * @property string $doctor_member_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\MedicalRecord|null $medicalRecord
 * @property-read \App\Models\Appointment|null $appointment
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\OrganizationMember|null $doctor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClinicalNote> $notes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ToothFinding> $toothFindings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClinicalFile> $files
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovement> $inventoryMovements
 */
class ClinicalEncounter extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'clinical_encounters';

    public const UPDATED_AT = null;

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'doctor_member_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'encounter_id')->orderBy('created_at');
    }

    public function toothFindings(): HasMany
    {
        return $this->hasMany(ToothFinding::class, 'encounter_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ClinicalFile::class, 'encounter_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'encounter_id');
    }

}

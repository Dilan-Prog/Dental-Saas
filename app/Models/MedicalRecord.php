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
 * Tabla `medical_records`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string|null $allergies
 * @property string|null $medical_history
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClinicalEncounter> $encounters
 */
class MedicalRecord extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'medical_records';

    public const UPDATED_AT = null;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class, 'medical_record_id')->orderBy('occurred_at');
    }

}

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
 * Tabla `tooth_findings`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property int $tooth_fdi
 * @property \App\Enums\DentitionType $dentition
 * @property \App\Enums\ToothCondition $condition
 * @property array|null $surfaces
 * @property string|null $encounter_id
 * @property string|null $patient_treatment_id
 * @property string $author_member_id
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $superseded_at
 * @property string|null $current_key
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\ClinicalEncounter|null $encounter
 * @property-read \App\Models\PatientTreatment|null $patientTreatment
 * @property-read \App\Models\OrganizationMember|null $author
 */
class ToothFinding extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'tooth_findings';

    public $timestamps = false;

    protected $hidden = ['current_key'];

    protected $casts = [
        'tooth_fdi' => 'integer',
        'dentition' => \App\Enums\DentitionType::class,
        'condition' => \App\Enums\ToothCondition::class,
        'surfaces' => 'array',
        'recorded_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patientTreatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'author_member_id');
    }

    /** Hallazgos vigentes (estado actual del odontograma). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

}

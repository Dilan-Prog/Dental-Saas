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
 * Tabla `consent_acceptances`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string|null $clinical_file_id
 * @property \Illuminate\Support\Carbon $accepted_at
 * @property string $accepted_by_name
 * @property string $method
 * @property string|null $witness_member_id
 * @property string|null $content_hash
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\ClinicalFile|null $clinicalFile
 * @property-read \App\Models\OrganizationMember|null $witness
 */
class ConsentAcceptance extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'consent_acceptances';

    public $timestamps = false;

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function clinicalFile(): BelongsTo
    {
        return $this->belongsTo(ClinicalFile::class, 'clinical_file_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'witness_member_id');
    }

}

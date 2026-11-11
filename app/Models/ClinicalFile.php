<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `clinical_files`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property \App\Enums\ClinicalFileType $file_type
 * @property string $display_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $storage_key
 * @property string|null $sha256
 * @property string $uploaded_by_member_id
 * @property string|null $encounter_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\ClinicalEncounter|null $encounter
 * @property-read \App\Models\OrganizationMember|null $uploader
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ConsentAcceptance> $consentAcceptances
 */
class ClinicalFile extends BaseModel
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'clinical_files';

    public const UPDATED_AT = null;

    protected $casts = [
        'file_type' => \App\Enums\ClinicalFileType::class,
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'uploaded_by_member_id');
    }

    public function consentAcceptances(): HasMany
    {
        return $this->hasMany(ConsentAcceptance::class, 'clinical_file_id');
    }

}

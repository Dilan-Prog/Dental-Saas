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
 * Tabla `patient_guardians`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string $full_name
 * @property string|null $relationship
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_primary
 *
 * @property-read \App\Models\Patient|null $patient
 */
class PatientGuardian extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'patient_guardians';

    public $timestamps = false;

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

}

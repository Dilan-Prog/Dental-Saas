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
 * Tabla `patient_fiscal_profiles`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string $rfc
 * @property string $legal_name
 * @property string $tax_regime_code
 * @property string $fiscal_postal_code
 * @property string|null $default_cfdi_use
 * @property bool $is_default
 *
 * @property-read \App\Models\Patient|null $patient
 */
class PatientFiscalProfile extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'patient_fiscal_profiles';

    public $timestamps = false;

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `organization_fiscal_settings`.
 *
 * @property string $organization_id
 * @property string|null $tax_regime_code
 * @property string|null $fiscal_postal_code
 * @property string|null $pac_provider
 * @property string|null $default_series
 * @property string|null $csd_certificate_secret_ref
 * @property string|null $csd_key_secret_ref
 * @property string|null $pac_credentials_secret_ref
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\Organization|null $organization
 */
class OrganizationFiscalSetting extends BasePivotModel
{
    protected $table = 'organization_fiscal_settings';

    protected $primaryKey = 'organization_id';

    public const CREATED_AT = null;

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

}

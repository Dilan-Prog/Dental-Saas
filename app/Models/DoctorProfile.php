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
 * Tabla `doctor_profiles`.
 *
 * @property string $member_id
 * @property string $organization_id
 * @property string|null $professional_license
 * @property string|null $specialty
 * @property string|null $signature_image_storage_key
 *
 * @property-read \App\Models\OrganizationMember|null $member
 */
class DoctorProfile extends BasePivotModel
{
    use BelongsToOrganization;

    protected $table = 'doctor_profiles';

    protected $primaryKey = 'member_id';

    public $timestamps = false;

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

}

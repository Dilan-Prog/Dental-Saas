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
 * Tabla `treatment_categories`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Treatment> $treatments
 */
class TreatmentCategory extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'treatment_categories';

    public $timestamps = false;

    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class, 'category_id');
    }

}

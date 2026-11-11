<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `tax_profiles`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $tax_code
 * @property string $rate
 * @property bool $is_exempt
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Treatment> $treatments
 */
class TaxProfile extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'tax_profiles';

    public $timestamps = false;

    protected $casts = [
        'rate' => 'decimal:6',
        'is_exempt' => 'boolean',
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class, 'tax_profile_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

}

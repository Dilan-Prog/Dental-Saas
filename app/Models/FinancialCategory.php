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
 * Tabla `financial_categories`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property \App\Enums\MovementDirection $direction
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FinancialMovement> $movements
 */
class FinancialCategory extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'financial_categories';

    public $timestamps = false;

    protected $casts = [
        'direction' => \App\Enums\MovementDirection::class,
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(FinancialMovement::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

}

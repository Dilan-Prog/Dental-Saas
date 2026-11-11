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
 * Tabla `inventory_stock`.
 *
 * @property string $organization_id
 * @property string $branch_id
 * @property string $item_id
 * @property string $quantity_on_hand
 * @property string $min_quantity
 * @property string|null $location
 * @property string $avg_unit_cost
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\InventoryItem|null $item
 */
class InventoryStock extends BasePivotModel
{
    use BelongsToOrganization;

    protected $table = 'inventory_stock';

    protected $primaryKey = null;

    protected array $compositeKey = ['branch_id', 'item_id'];

    public const CREATED_AT = null;

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'avg_unit_cost' => 'decimal:4',
        'updated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    /** Existencia en o por debajo del mínimo. */
    public function scopeLow(Builder $query): Builder
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'min_quantity');
    }

    public function isLow(): bool
    {
        return (float) $this->quantity_on_hand <= (float) $this->min_quantity;
    }

}

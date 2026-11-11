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
 * Tabla `inventory_items`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $sku
 * @property string|null $category
 * @property string $unit_of_measure
 * @property string|null $supplier_id
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryStock> $stock
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryLot> $lots
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovement> $movements
 */
class InventoryItem extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'inventory_items';

    public $timestamps = false;

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id')->orderBy('occurred_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    /** Existencia total sumando todas las sucursales. */
    public function totalStock(): string
    {
        return number_format((float) $this->stock()->sum('quantity_on_hand'), 4, '.', '');
    }

}

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
 * Tabla `inventory_lots`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $item_id
 * @property string $lot_code
 * @property \Illuminate\Support\Carbon|null $expires_on
 * @property string $quantity_on_hand
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\InventoryItem|null $item
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryMovement> $movements
 */
class InventoryLot extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'inventory_lots';

    public $timestamps = false;

    protected $casts = [
        'expires_on' => 'date',
        'quantity_on_hand' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'lot_id');
    }

}

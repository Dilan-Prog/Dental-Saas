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
 * Tabla `inventory_movements`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $item_id
 * @property \App\Enums\InventoryMovementType $movement_type
 * @property string $quantity
 * @property string|null $unit_cost
 * @property string|null $reference
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string $actor_user_id
 * @property string|null $encounter_id
 * @property string|null $lot_id
 * @property string|null $reverses_movement_id
 * @property string|null $initial_stock_key
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\InventoryItem|null $item
 * @property-read \App\Models\User|null $actor
 * @property-read \App\Models\ClinicalEncounter|null $encounter
 * @property-read \App\Models\InventoryLot|null $lot
 * @property-read \App\Models\InventoryMovement|null $reversesMovement
 * @property-read \App\Models\InventoryMovement|null $reversal
 */
class InventoryMovement extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'inventory_movements';

    public $timestamps = false;

    protected $hidden = ['initial_stock_key'];

    protected $casts = [
        'movement_type' => \App\Enums\InventoryMovementType::class,
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    public function reversesMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'reverses_movement_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(InventoryMovement::class, 'reverses_movement_id');
    }

}

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
 * Tabla `suppliers`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryItem> $items
 */
class Supplier extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'suppliers';

    public $timestamps = false;

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'supplier_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

}

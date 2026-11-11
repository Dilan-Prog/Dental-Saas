<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `medications`.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $name
 * @property string|null $active_ingredient
 * @property string|null $strength
 * @property string|null $presentation
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PrescriptionItem> $prescriptionItems
 */
class Medication extends BaseModel
{
    protected $table = 'medications';

    public $timestamps = false;

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class, 'medication_id');
    }

    /** Catálogo propio + catálogo global (organization_id nulo). */
    protected static function booted(): void
    {
        static::addGlobalScope('organization_or_global', function (Builder $query) {
            $id = Organization::currentId();
            if ($id !== null) {
                $query->where(function (Builder $w) use ($id) {
                    $w->where('medications.organization_id', $id)->orWhereNull('medications.organization_id');
                });
            }
        });

        static::creating(function (self $m) {
            if (! array_key_exists('organization_id', $m->getAttributes())) {
                $m->organization_id = Organization::currentId();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

}

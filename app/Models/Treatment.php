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
 * Tabla `treatments`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $category_id
 * @property string $name
 * @property int $duration_minutes
 * @property string|null $sat_product_key
 * @property string|null $sat_unit_key
 * @property string $price
 * @property string $currency
 * @property string|null $tax_profile_id
 * @property \App\Enums\RecordStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \App\Models\TreatmentCategory|null $category
 * @property-read \App\Models\TaxProfile|null $taxProfile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $appointments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\QuoteItem> $quoteItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientTreatment> $patientTreatments
 */
class Treatment extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'treatments';

    protected $casts = [
        'duration_minutes' => 'integer',
        'price' => 'decimal:4',
        'status' => \App\Enums\RecordStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TreatmentCategory::class, 'category_id');
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class, 'tax_profile_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'treatment_id');
    }

    public function quoteItems(): HasMany
    {
        return $this->hasMany(QuoteItem::class, 'treatment_id');
    }

    public function patientTreatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class, 'treatment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    /** Precio con impuesto (según su perfil de impuestos), redondeado a 2 decimales. */
    public function priceWithTax(): string
    {
        $rate = ($this->taxProfile && ! $this->taxProfile->is_exempt) ? (float) $this->taxProfile->rate : 0.0;

        return number_format(round((float) $this->price * (1 + $rate), 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

}

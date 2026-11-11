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
 * Tabla `patient_treatments`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string $treatment_id
 * @property string|null $quote_item_id
 * @property int|null $tooth_fdi
 * @property \App\Enums\PlannedTreatmentStatus $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\Treatment|null $treatment
 * @property-read \App\Models\QuoteItem|null $quoteItem
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ToothFinding> $toothFindings
 */
class PatientTreatment extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'patient_treatments';

    public $timestamps = false;

    protected $casts = [
        'tooth_fdi' => 'integer',
        'status' => \App\Enums\PlannedTreatmentStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function toothFindings(): HasMany
    {
        return $this->hasMany(ToothFinding::class, 'patient_treatment_id');
    }

}

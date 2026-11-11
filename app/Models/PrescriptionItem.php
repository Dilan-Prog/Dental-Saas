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
 * Tabla `prescription_items`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $prescription_id
 * @property int $line_number
 * @property string|null $medication_id
 * @property string $medication_name
 * @property string|null $strength
 * @property string|null $presentation
 * @property string $dose
 * @property string|null $route
 * @property string $frequency
 * @property string|null $duration
 * @property string|null $instructions
 *
 * @property-read \App\Models\Prescription|null $prescription
 * @property-read \App\Models\Medication|null $medication
 */
class PrescriptionItem extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'prescription_items';

    public $timestamps = false;

    protected $casts = [
        'line_number' => 'integer',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }

}

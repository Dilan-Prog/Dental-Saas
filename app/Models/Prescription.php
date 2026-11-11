<?php

namespace App\Models;

use App\Enums\SigningStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `prescriptions`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $folio
 * @property string $patient_id
 * @property string $doctor_member_id
 * @property \Illuminate\Support\Carbon $issued_on
 * @property string|null $diagnosis
 * @property string|null $general_instructions
 * @property \App\Enums\SigningStatus $status
 * @property \Illuminate\Support\Carbon|null $signed_at
 * @property string|null $doctor_name_snapshot
 * @property string|null $doctor_license_snapshot
 * @property string|null $content_hash
 * @property string|null $pdf_storage_key
 * @property string|null $repeated_from_id
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\OrganizationMember|null $doctor
 * @property-read \App\Models\Prescription|null $repeatedFrom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Prescription> $repeats
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PrescriptionItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PrescriptionDelivery> $deliveries
 */
class Prescription extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'prescriptions';

    public const UPDATED_AT = null;

    protected $casts = [
        'issued_on' => 'date',
        'status' => \App\Enums\SigningStatus::class,
        'signed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'doctor_member_id');
    }

    public function repeatedFrom(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'repeated_from_id');
    }

    public function repeats(): HasMany
    {
        return $this->hasMany(Prescription::class, 'repeated_from_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class, 'prescription_id')->orderBy('line_number');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PrescriptionDelivery::class, 'prescription_id');
    }

    public function isSigned(): bool
    {
        return $this->status === SigningStatus::Firmada;
    }

    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('status', SigningStatus::Firmada->value);
    }

}

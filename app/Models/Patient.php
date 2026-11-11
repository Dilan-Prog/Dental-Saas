<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\FiscalStatus;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `patients`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $folio
 * @property string $full_name
 * @property \Illuminate\Support\Carbon|null $birth_date
 * @property \App\Enums\SexType $sex
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $assigned_doctor_member_id
 * @property \App\Enums\RecordStatus $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $anonymized_at
 *
 * @property-read \App\Models\OrganizationMember|null $assignedDoctor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientFiscalProfile> $fiscalProfiles
 * @property-read \App\Models\PatientFiscalProfile|null $defaultFiscalProfile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientGuardian> $guardians
 * @property-read \App\Models\MedicalRecord|null $medicalRecord
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $appointments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Prescription> $prescriptions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Quote> $quotes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Invoice> $invoices
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ToothFinding> $toothFindings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClinicalFile> $clinicalFiles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientTreatment> $treatments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ConsentAcceptance> $consentAcceptances
 */
class Patient extends BaseModel
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'patients';

    protected $casts = [
        'birth_date' => 'date',
        'sex' => \App\Enums\SexType::class,
        'status' => \App\Enums\RecordStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    public function assignedDoctor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'assigned_doctor_member_id');
    }

    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(PatientFiscalProfile::class, 'patient_id');
    }

    public function defaultFiscalProfile(): HasOne
    {
        return $this->hasOne(PatientFiscalProfile::class, 'patient_id')->where('is_default', true);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(PatientGuardian::class, 'patient_id');
    }

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class, 'patient_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id')->orderBy('starts_at');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'patient_id')->orderBy('issued_on');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'patient_id')->orderBy('quote_date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'patient_id')->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'patient_id')->orderBy('paid_at');
    }

    public function toothFindings(): HasMany
    {
        return $this->hasMany(ToothFinding::class, 'patient_id')->orderBy('recorded_at');
    }

    public function clinicalFiles(): HasMany
    {
        return $this->hasMany(ClinicalFile::class, 'patient_id')->orderBy('created_at');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class, 'patient_id');
    }

    public function consentAcceptances(): HasMany
    {
        return $this->hasMany(ConsentAcceptance::class, 'patient_id');
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }

    /** Búsqueda por nombre, folio, teléfono o correo. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.$term.'%';

        return $query->where(function (Builder $w) use ($like) {
            $w->where('full_name', 'like', $like)
                ->orWhere('folio', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /** Saldo pendiente = facturas vigentes - aplicaciones vigentes (cadena con 2 decimales). */
    public function pendingBalance(): string
    {
        $invoices = Invoice::query()
            ->where('patient_id', $this->id)
            ->whereNotIn('fiscal_status', [FiscalStatus::Borrador->value, FiscalStatus::Cancelada->value]);

        $total = (float) (clone $invoices)->sum('total');
        $applied = (float) PaymentApplication::query()
            ->whereIn('invoice_id', (clone $invoices)->select('id'))
            ->whereNull('reversed_at')
            ->sum('applied_amount');

        return number_format(round($total - $applied, 2), 2, '.', '');
    }

    public function lastVisit(): ?Carbon
    {
        $max = $this->appointments()->where('status', AppointmentStatus::Completada->value)->max('starts_at');

        return $max ? Carbon::parse($max) : null;
    }

}

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
 * Tabla `payments`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $patient_id
 * @property \Illuminate\Support\Carbon $paid_at
 * @property string $amount
 * @property string $currency
 * @property \App\Enums\PaymentMethod $method
 * @property string|null $reference
 * @property \App\Enums\PaymentStatus $status
 * @property string $idempotency_key
 * @property string|null $cash_session_id
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property string|null $transfer_ref_key
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\CashSession|null $cashSession
 * @property-read \App\Models\User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaymentApplication> $applications
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaymentReversal> $reversals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaymentComplement> $complements
 * @property-read \App\Models\FinancialMovement|null $financialMovement
 */
class Payment extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'payments';

    public const UPDATED_AT = null;

    protected $hidden = ['transfer_ref_key'];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'method' => \App\Enums\PaymentMethod::class,
        'status' => \App\Enums\PaymentStatus::class,
        'created_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'payment_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class, 'payment_id');
    }

    public function complements(): HasMany
    {
        return $this->hasMany(PaymentComplement::class, 'payment_id');
    }

    public function financialMovement(): HasOne
    {
        return $this->hasOne(FinancialMovement::class, 'payment_id');
    }

    /** Monto del pago aún no aplicado a facturas. */
    public function unappliedAmount(): string
    {
        $applied = (float) $this->applications()->whereNull('reversed_at')->sum('applied_amount');

        return number_format(round((float) $this->amount - $applied, 2), 2, '.', '');
    }

}

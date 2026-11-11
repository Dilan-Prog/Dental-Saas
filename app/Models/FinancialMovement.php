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
 * Tabla `financial_movements`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property \App\Enums\MovementDirection $direction
 * @property string $category_id
 * @property string $concept
 * @property \Illuminate\Support\Carbon $occurred_on
 * @property string $amount
 * @property string $currency
 * @property \App\Enums\PaymentMethod $method
 * @property string|null $payment_id
 * @property string|null $cash_session_id
 * @property string|null $receipt_storage_key
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property string|null $reversed_of_id
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\FinancialCategory|null $category
 * @property-read \App\Models\Payment|null $payment
 * @property-read \App\Models\CashSession|null $cashSession
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\FinancialMovement|null $reversedOf
 * @property-read \App\Models\FinancialMovement|null $reversal
 */
class FinancialMovement extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'financial_movements';

    public const UPDATED_AT = null;

    protected $casts = [
        'direction' => \App\Enums\MovementDirection::class,
        'occurred_on' => 'date',
        'amount' => 'decimal:2',
        'method' => \App\Enums\PaymentMethod::class,
        'created_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedOf(): BelongsTo
    {
        return $this->belongsTo(FinancialMovement::class, 'reversed_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(FinancialMovement::class, 'reversed_of_id');
    }

    /**
     * Movimientos "efectivos" para reportes: excluye los revertidos y sus
     * reversiones (el par se anula, el efecto neto es cero).
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('financial_movements.reversed_of_id')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('financial_movements as rev')
                    ->whereColumn('rev.reversed_of_id', 'financial_movements.id');
            });
    }

    /** Movimiento capturado a mano (no viene de un pago ni es una reversión). */
    public function isManual(): bool
    {
        return $this->payment_id === null && $this->reversed_of_id === null;
    }

    public function isReversal(): bool
    {
        return $this->reversed_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal !== null
            : $this->reversal()->exists();
    }

}

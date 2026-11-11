<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `cash_sessions`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $cash_register_id
 * @property string $opened_by
 * @property \Illuminate\Support\Carbon $opened_at
 * @property string $opening_amount
 * @property \App\Enums\CashSessionStatus $status
 * @property string|null $closed_by
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $difference
 * @property string|null $notes
 * @property string|null $open_register_key
 *
 * @property-read \App\Models\CashRegister|null $cashRegister
 * @property-read \App\Models\User|null $opener
 * @property-read \App\Models\User|null $closer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CashSessionAdjustment> $adjustments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FinancialMovement> $movements
 */
class CashSession extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'cash_sessions';

    public $timestamps = false;

    protected $hidden = ['open_register_key'];

    protected $casts = [
        'opened_at' => 'datetime',
        'opening_amount' => 'decimal:2',
        'status' => \App\Enums\CashSessionStatus::class,
        'closed_at' => 'datetime',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CashSessionAdjustment::class, 'cash_session_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_session_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinancialMovement::class, 'cash_session_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashSessionStatus::Abierta->value);
    }

    /** Efectivo esperado = apertura + ingresos en efectivo - egresos en efectivo + ajustes. */
    public function expectedCash(): string
    {
        $cash = $this->movements()->where('method', PaymentMethod::Efectivo->value);
        $in = (float) (clone $cash)->where('direction', MovementDirection::Ingreso->value)->sum('amount');
        $out = (float) (clone $cash)->where('direction', MovementDirection::Egreso->value)->sum('amount');
        $adj = (float) $this->adjustments()->sum('amount');

        return number_format(round((float) $this->opening_amount + $in - $out + $adj, 2), 2, '.', '');
    }

}

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
 * Tabla `cash_session_adjustments`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $cash_session_id
 * @property string $amount
 * @property string $reason
 * @property string $created_by
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\CashSession|null $cashSession
 * @property-read \App\Models\User|null $creator
 */
class CashSessionAdjustment extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'cash_session_adjustments';

    public const UPDATED_AT = null;

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}

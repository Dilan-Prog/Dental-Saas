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
 * Tabla `payment_reversals`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $payment_id
 * @property string $amount
 * @property string $reason
 * @property string|null $reversed_by
 * @property \Illuminate\Support\Carbon $reversed_at
 *
 * @property-read \App\Models\Payment|null $payment
 * @property-read \App\Models\User|null $reverser
 */
class PaymentReversal extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'payment_reversals';

    public $timestamps = false;

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

}

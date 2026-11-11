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
 * Tabla `payment_applications`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property string $applied_amount
 * @property \Illuminate\Support\Carbon $applied_at
 * @property \Illuminate\Support\Carbon|null $reversed_at
 *
 * @property-read \App\Models\Payment|null $payment
 * @property-read \App\Models\Invoice|null $invoice
 */
class PaymentApplication extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'payment_applications';

    public $timestamps = false;

    protected $casts = [
        'applied_amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

}

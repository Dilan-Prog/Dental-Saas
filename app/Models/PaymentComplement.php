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
 * Tabla `payment_complements`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $complement_invoice_id
 * @property string $payment_id
 * @property string $related_invoice_id
 * @property int|null $installment_number
 * @property string|null $previous_balance
 * @property string|null $paid_amount
 * @property string|null $remaining_balance
 *
 * @property-read \App\Models\Invoice|null $complementInvoice
 * @property-read \App\Models\Payment|null $payment
 * @property-read \App\Models\Invoice|null $relatedInvoice
 */
class PaymentComplement extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'payment_complements';

    public $timestamps = false;

    protected $casts = [
        'installment_number' => 'integer',
        'previous_balance' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
    ];

    public function complementInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'complement_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

}

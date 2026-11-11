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
 * Tabla `quotes`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $folio
 * @property string $patient_id
 * @property string $doctor_member_id
 * @property string $branch_id
 * @property \Illuminate\Support\Carbon $quote_date
 * @property \App\Enums\QuoteStatus $status
 * @property string $currency
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\OrganizationMember|null $doctor
 * @property-read \App\Models\Branch|null $branch
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\QuoteItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\QuoteStatusHistory> $statusHistory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Invoice> $invoices
 */
class Quote extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'quotes';

    public const UPDATED_AT = null;

    protected $casts = [
        'quote_date' => 'date',
        'status' => \App\Enums\QuoteStatus::class,
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'sent_at' => 'datetime',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class, 'quote_id')->orderBy('line_number');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(QuoteStatusHistory::class, 'quote_id')->orderBy('created_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'quote_id');
    }

    /** Recalcula y guarda los totales a partir de las partidas (total = subtotal - descuento + impuestos). */
    public function recalculateTotals(): self
    {
        $items = $this->items()->get();
        $subtotal = round($items->sum(fn ($i) => (float) $i->line_subtotal), 2);
        $discount = round($items->sum(fn ($i) => (float) $i->line_discount), 2);
        $tax = round($items->sum(fn ($i) => (float) $i->line_tax), 2);

        $this->subtotal = $subtotal;
        $this->discount_total = $discount;
        $this->tax_total = $tax;
        $this->total = round($subtotal - $discount + $tax, 2);
        $this->save();

        return $this;
    }

}

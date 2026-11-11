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
 * Tabla `invoice_items`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property int $line_number
 * @property string|null $treatment_id
 * @property string|null $quote_item_id
 * @property string $description
 * @property string $sat_product_key
 * @property string $sat_unit_key
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount
 * @property string $amount
 * @property string $tax_object_code
 *
 * @property-read \App\Models\Invoice|null $invoice
 * @property-read \App\Models\Treatment|null $treatment
 * @property-read \App\Models\QuoteItem|null $quoteItem
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceItemTax> $taxes
 */
class InvoiceItem extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'invoice_items';

    public $timestamps = false;

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceItemTax::class, 'invoice_item_id');
    }

}

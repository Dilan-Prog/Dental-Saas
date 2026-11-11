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
 * Tabla `invoice_item_taxes`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_item_id
 * @property string $tax_code
 * @property string $factor_type
 * @property string|null $rate
 * @property string $base
 * @property string|null $amount
 * @property bool $is_withholding
 *
 * @property-read \App\Models\InvoiceItem|null $invoiceItem
 */
class InvoiceItemTax extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'invoice_item_taxes';

    public $timestamps = false;

    protected $casts = [
        'rate' => 'decimal:6',
        'base' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_withholding' => 'boolean',
    ];

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

}

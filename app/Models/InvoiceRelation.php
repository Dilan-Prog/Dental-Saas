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
 * Tabla `invoice_relations`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $related_invoice_id
 * @property string $relation_type_code
 *
 * @property-read \App\Models\Invoice|null $invoice
 * @property-read \App\Models\Invoice|null $relatedInvoice
 */
class InvoiceRelation extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'invoice_relations';

    public $timestamps = false;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

}

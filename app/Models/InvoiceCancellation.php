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
 * Tabla `invoice_cancellations`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $reason_code
 * @property string|null $substitute_invoice_id
 * @property \App\Enums\CancellationStatus $status
 * @property string|null $requested_by
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon|null $response_at
 * @property string|null $response_detail
 * @property string|null $pac_request_id
 *
 * @property-read \App\Models\Invoice|null $invoice
 * @property-read \App\Models\Invoice|null $substituteInvoice
 * @property-read \App\Models\User|null $requester
 * @property-read \App\Models\PacRequest|null $pacRequest
 */
class InvoiceCancellation extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'invoice_cancellations';

    public $timestamps = false;

    protected $casts = [
        'status' => \App\Enums\CancellationStatus::class,
        'requested_at' => 'datetime',
        'response_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function substituteInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'substitute_invoice_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function pacRequest(): BelongsTo
    {
        return $this->belongsTo(PacRequest::class, 'pac_request_id');
    }

}

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
 * Tabla `pac_requests`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property \App\Enums\PacRequestKind $kind
 * @property \App\Enums\PacRequestStatus $status
 * @property string $idempotency_key
 * @property int $attempt_number
 * @property string|null $request_payload_ref
 * @property string|null $response_code
 * @property string|null $response_message
 * @property string|null $error_detail
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon|null $next_retry_at
 *
 * @property-read \App\Models\Invoice|null $invoice
 */
class PacRequest extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'pac_requests';

    public $timestamps = false;

    protected $casts = [
        'kind' => \App\Enums\PacRequestKind::class,
        'status' => \App\Enums\PacRequestStatus::class,
        'attempt_number' => 'integer',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

}

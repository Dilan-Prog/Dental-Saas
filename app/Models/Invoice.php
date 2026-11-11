<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `invoices`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $internal_folio
 * @property string|null $series
 * @property int|null $fiscal_folio
 * @property string|null $uuid_fiscal
 * @property \App\Enums\CfdiType $cfdi_type
 * @property string $patient_id
 * @property string|null $doctor_member_id
 * @property string $branch_id
 * @property string|null $quote_id
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $stamped_at
 * @property array $issuer_snapshot
 * @property array $receiver_snapshot
 * @property string $cfdi_use
 * @property \App\Enums\CfdiPaymentMethod $cfdi_payment_method
 * @property string $cfdi_payment_form
 * @property string $currency
 * @property string|null $exchange_rate
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property \App\Enums\FiscalStatus $fiscal_status
 * @property \App\Enums\CollectionStatus $collection_status
 * @property string|null $xml_storage_key
 * @property string|null $pdf_storage_key
 * @property string|null $pac_provider
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\OrganizationMember|null $doctor
 * @property-read \App\Models\Branch|null $branch
 * @property-read \App\Models\Quote|null $quote
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceRelation> $relations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PacRequest> $pacRequests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceCancellation> $cancellations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaymentApplication> $applications
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaymentComplement> $paymentComplements
 */
class Invoice extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'invoices';

    public const UPDATED_AT = null;

    protected $casts = [
        'fiscal_folio' => 'integer',
        'cfdi_type' => \App\Enums\CfdiType::class,
        'issued_at' => 'datetime',
        'stamped_at' => 'datetime',
        'issuer_snapshot' => 'array',
        'receiver_snapshot' => 'array',
        'cfdi_payment_method' => \App\Enums\CfdiPaymentMethod::class,
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'fiscal_status' => \App\Enums\FiscalStatus::class,
        'collection_status' => \App\Enums\CollectionStatus::class,
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id')->orderBy('line_number');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(InvoiceRelation::class, 'invoice_id');
    }

    public function pacRequests(): HasMany
    {
        return $this->hasMany(PacRequest::class, 'invoice_id')->orderBy('requested_at');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(InvoiceCancellation::class, 'invoice_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'invoice_id');
    }

    public function paymentComplements(): HasMany
    {
        return $this->hasMany(PaymentComplement::class, 'related_invoice_id');
    }

    /** Suma de aplicaciones de pago vigentes (no revertidas). */
    public function appliedAmount(): string
    {
        $sum = (float) $this->applications()->whereNull('reversed_at')->sum('applied_amount');

        return number_format(round($sum, 2), 2, '.', '');
    }

    /** Saldo = total - aplicaciones vigentes. */
    public function balance(): string
    {
        return number_format(round((float) $this->total - (float) $this->appliedAmount(), 2), 2, '.', '');
    }

    /** Filas de payment_complements donde ESTA factura (tipo P) es el complemento. */
    public function complementRows(): HasMany
    {
        return $this->hasMany(PaymentComplement::class, 'complement_invoice_id');
    }

    /** Solo las facturas en borrador se pueden editar o eliminar. */
    public function isEditable(): bool
    {
        return $this->fiscal_status === FiscalStatus::Borrador;
    }

    /** Admite cobros: emitida (pendiente de timbrar o timbrada), con saldo y no cancelada. */
    public function acceptsPayments(): bool
    {
        return $this->cfdi_type === \App\Enums\CfdiType::Ingreso
            && in_array($this->fiscal_status, [FiscalStatus::PendienteTimbrado, FiscalStatus::Timbrada], true)
            && $this->collection_status !== CollectionStatus::Pagada;
    }

    /** Se puede cancelar mientras no este en borrador ni ya cancelada/en cancelacion. */
    public function isCancelable(): bool
    {
        return in_array($this->fiscal_status, [FiscalStatus::PendienteTimbrado, FiscalStatus::Timbrada, FiscalStatus::Error], true);
    }

    public function isStamped(): bool
    {
        return $this->fiscal_status?->isStamped() ?? false;
    }

    public function isDraft(): bool
    {
        return $this->fiscal_status === FiscalStatus::Borrador;
    }

    /** Facturas con saldo por cobrar (timbradas o pendientes, no canceladas ni en borrador). */
    public function scopeReceivable(Builder $query): Builder
    {
        return $query
            ->whereNotIn('fiscal_status', [FiscalStatus::Borrador->value, FiscalStatus::Cancelada->value])
            ->where('collection_status', '!=', CollectionStatus::Pagada->value);
    }

}

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
 * Tabla `quote_items`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $quote_id
 * @property int $line_number
 * @property string|null $treatment_id
 * @property string $description_snapshot
 * @property string|null $sat_product_key_snapshot
 * @property int|null $tooth_fdi
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $tax_rate_snapshot
 * @property bool $tax_is_exempt
 * @property string $line_subtotal
 * @property string $line_discount
 * @property string $line_tax
 * @property string $line_total
 *
 * @property-read \App\Models\Quote|null $quote
 * @property-read \App\Models\Treatment|null $treatment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PatientTreatment> $patientTreatments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceItem> $invoiceItems
 */
class QuoteItem extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'quote_items';

    public $timestamps = false;

    protected $casts = [
        'line_number' => 'integer',
        'tooth_fdi' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_pct' => 'decimal:2',
        'tax_rate_snapshot' => 'decimal:6',
        'tax_is_exempt' => 'boolean',
        'line_subtotal' => 'decimal:2',
        'line_discount' => 'decimal:2',
        'line_tax' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }

    public function patientTreatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class, 'quote_item_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'quote_item_id');
    }

    /**
     * Calcula los importes de una partida con redondeo half-up a 2 decimales por concepto.
     *
     * @return array{line_subtotal: float, line_discount: float, line_tax: float, line_total: float}
     */
    public static function computeLine(float $quantity, float $unitPrice, float $discountPct, float $taxRate, bool $exempt = false): array
    {
        $subtotal = round($quantity * $unitPrice, 2, PHP_ROUND_HALF_UP);
        $discount = round($subtotal * $discountPct / 100, 2, PHP_ROUND_HALF_UP);
        $base = round($subtotal - $discount, 2);
        $tax = $exempt ? 0.0 : round($base * $taxRate, 2, PHP_ROUND_HALF_UP);

        return [
            'line_subtotal' => $subtotal,
            'line_discount' => $discount,
            'line_tax' => $tax,
            'line_total' => round($base + $tax, 2),
        ];
    }

    /** Rellena line_* de esta partida con sus valores actuales. */
    public function fillComputed(): self
    {
        return $this->fill(self::computeLine(
            (float) $this->quantity,
            (float) $this->unit_price,
            (float) $this->discount_pct,
            (float) $this->tax_rate_snapshot,
            (bool) $this->tax_is_exempt,
        ));
    }

}

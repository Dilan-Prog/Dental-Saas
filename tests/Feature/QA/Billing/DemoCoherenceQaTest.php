<?php

namespace Tests\Feature\QA\Billing;

use App\Enums\CollectionStatus;
use App\Enums\FiscalStatus;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Prescription;
use App\Models\Quote;
use App\Services\Dashboard\DashboardService;
use App\Services\Facturacion\BillingMetrics;
use App\Support\Money;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** QA: invariantes de coherencia entre presupuestos, facturas, pagos, finanzas y folios con datos demo. */
class DemoCoherenceQaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_quote_lines_and_totals_are_consistent(): void
    {
        $this->assertGreaterThan(0, Quote::count());
        foreach (Quote::with('items')->get() as $q) {
            $this->assertSame(Money::round($q->items->sum('line_subtotal')), Money::round($q->subtotal), "subtotal {$q->folio}");
            $this->assertSame(Money::round($q->items->sum('line_discount')), Money::round($q->discount_total), "descuento {$q->folio}");
            $this->assertSame(Money::round($q->items->sum('line_tax')), Money::round($q->tax_total), "impuesto {$q->folio}");
            $this->assertSame(Money::round($q->subtotal - $q->discount_total + $q->tax_total), Money::round($q->total), "total {$q->folio}");
            foreach ($q->items as $i) {
                $this->assertSame(Money::round($i->line_subtotal - $i->line_discount + $i->line_tax), Money::round($i->line_total));
                $this->assertSame(Money::round($i->quantity * $i->unit_price), Money::round($i->line_subtotal));
            }
        }
    }

    public function test_invoice_totals_collection_status_and_payments_are_consistent(): void
    {
        $this->assertGreaterThan(0, Invoice::count());
        foreach (Invoice::with('items.taxes')->get() as $inv) {
            $this->assertSame(Money::round($inv->items->sum('amount')), Money::round($inv->subtotal), "subtotal {$inv->internal_folio}");
            $this->assertSame(Money::round($inv->subtotal - $inv->discount_total + $inv->tax_total), Money::round($inv->total), "total {$inv->internal_folio}");

            $applied = (string) PaymentApplication::where('invoice_id', $inv->id)->whereNull('reversed_at')->sum('applied_amount');
            $this->assertLessThanOrEqual(0, Money::cmp($applied, $inv->total), "aplicado > total en {$inv->internal_folio}");

            if (! in_array($inv->fiscal_status, [FiscalStatus::Cancelada, FiscalStatus::Borrador], true)) {
                $expected = Money::cmp($applied, '0') === 0 ? CollectionStatus::Pendiente
                    : (Money::cmp($applied, $inv->total) >= 0 ? CollectionStatus::Pagada : CollectionStatus::PagoParcial);
                $this->assertSame($expected, $inv->collection_status, "cobranza {$inv->internal_folio}");
            }
            // Nada inventa un UUID fiscal.
            $this->assertNull($inv->uuid_fiscal, "uuid inventado en {$inv->internal_folio}");
        }

        foreach (Payment::all() as $p) {
            $applied = (string) PaymentApplication::where('payment_id', $p->id)->whereNull('reversed_at')->sum('applied_amount');
            $this->assertLessThanOrEqual(0, Money::cmp($applied, $p->amount), 'aplicado > monto del pago');
            // Un pago = un movimiento financiero de ingreso con el mismo importe.
            $movs = DB::table('financial_movements')->where('payment_id', $p->id)->get();
            $this->assertCount(1, $movs, 'un pago, un movimiento');
            $this->assertSame('ingreso', $movs[0]->direction);
            $this->assertSame(Money::round($p->amount), Money::round($movs[0]->amount));
        }
    }

    public function test_invoice_from_quote_matches_the_quote_and_quotes_are_invoiced_once(): void
    {
        foreach (Invoice::whereNotNull('quote_id')->get() as $inv) {
            $q = Quote::findOrFail($inv->quote_id);
            $this->assertSame(Money::round($q->total), Money::round($inv->total), "factura {$inv->internal_folio} vs {$q->folio}");
            $this->assertSame(Money::round($q->subtotal), Money::round($inv->subtotal));
            $this->assertSame(Money::round($q->tax_total), Money::round($inv->tax_total));
            $this->assertSame(Money::round($q->discount_total), Money::round($inv->discount_total));
        }
        $dup = Invoice::whereNotNull('quote_id')->whereNotIn('fiscal_status', ['cancelada'])
            ->select('quote_id')->groupBy('quote_id')->havingRaw('count(*) > 1')->count();
        $this->assertSame(0, $dup, 'un presupuesto con más de una factura vigente');
    }

    public function test_patient_balance_receivables_and_dashboard_agree(): void
    {
        $sum = '0';
        foreach (Patient::all() as $p) {
            $expected = '0';
            foreach (Invoice::where('patient_id', $p->id)->whereNotIn('fiscal_status', ['borrador', 'cancelada'])->get() as $inv) {
                $expected = Money::add($expected, $inv->balance());
            }
            $this->assertSame(Money::round($expected), Money::round($p->pendingBalance()), "saldo de {$p->full_name}");
            $sum = Money::add($sum, $expected);
        }

        $dash = app(DashboardService::class)->receivable();
        $billing = BillingMetrics::summary();
        $this->assertSame(Money::round($sum), Money::round($dash['total']), 'Dashboard vs suma de saldos');
        $this->assertSame(Money::round($sum), Money::round($billing['receivable']), 'Facturación vs suma de saldos');
        $this->assertSame($dash['count'], $billing['receivable_count']);
    }

    public function test_folios_are_unique_consecutive_and_sequences_are_ahead(): void
    {
        $check = function (string $table, string $col, string $type, string $prefix, ?string $series = null) {
            $nums = DB::table($table)->pluck($col)->map(fn ($f) => (int) substr($f, strlen($prefix)))->sort()->values()->all();
            $this->assertNotEmpty($nums, $table);
            $this->assertSame(count($nums), count(array_unique($nums)), "duplicados en $table");
            $this->assertSame(range($nums[0], end($nums)), $nums, "huecos en $table");
            $last = (int) DB::table('folio_sequences')->where('document_type', $type)
                ->when($series !== null, fn ($q) => $q->where('series', $series))->max('last_value');
            $this->assertGreaterThanOrEqual(end($nums), $last, "secuencia atrasada de $table");
        };

        $check('quotes', 'folio', 'quote', 'PRE-');
        $check('prescriptions', 'folio', 'prescription', 'RX-');
        $check('patients', 'folio', 'patient', 'PAC-');
        $check('invoices', 'internal_folio', 'invoice', 'F-');

        // La siguiente factura no puede repetir folio (la serie por defecto es la que se consume).
        $next = app(\App\Services\Facturacion\InvoiceService::class)->create([
            'patient_id' => Patient::first()->id,
            'branch_id' => (string) \App\Models\Branch::main()->value('id'),
            'receiver_mode' => 'publico_general',
            'cfdi_use' => 'S01', 'cfdi_payment_method' => 'PUE', 'cfdi_payment_form' => '01',
            'items' => [[
                'description' => 'Consulta', 'sat_product_key' => '85121800', 'sat_unit_key' => 'E48',
                'quantity' => '1', 'unit_price' => '100', 'discount_pct' => '0', 'tax_object_code' => '01',
            ]],
        ]);
        $this->assertSame(1, Invoice::where('internal_folio', $next->internal_folio)->count());
    }

    public function test_demo_prescriptions_are_valid(): void
    {
        foreach (Prescription::with('items')->get() as $rx) {
            $this->assertGreaterThan(0, $rx->items->count());
            if ($rx->status->value === 'firmada') {
                $this->assertNotNull($rx->content_hash);
                $this->assertNotNull($rx->signed_at);
                $this->assertNotEmpty($rx->doctor_name_snapshot);
            } else {
                $this->assertNull($rx->content_hash);
            }
        }
    }
}

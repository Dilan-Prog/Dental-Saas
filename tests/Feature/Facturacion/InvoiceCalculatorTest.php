<?php

namespace Tests\Feature\Facturacion;

use App\Services\Facturacion\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private function iva(): array
    {
        return ['tax_code' => '002', 'rate' => '0.160000', 'is_exempt' => false];
    }

    public function test_iva_16_matches_prototype_amounts(): void
    {
        $r = InvoiceCalculator::calculate([[
            'quantity' => '1', 'unit_price' => '12000', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax' => $this->iva(),
        ]]);

        $this->assertSame('12000.00', $r['subtotal']);
        $this->assertSame('1920.00', $r['tax_total']);
        $this->assertSame('13920.00', $r['total']);
    }

    public function test_discount_reduces_the_tax_base(): void
    {
        $r = InvoiceCalculator::calculate([[
            'quantity' => '2', 'unit_price' => '1000', 'discount_pct' => '10', 'tax_object_code' => '02', 'tax' => $this->iva(),
        ]]);

        $this->assertSame('2000.00', $r['subtotal']);
        $this->assertSame('200.00', $r['discount_total']);
        $this->assertSame('288.00', $r['tax_total']);
        $this->assertSame('2088.00', $r['total']);
    }

    public function test_exempt_lines_have_no_tax_amount_but_keep_the_row(): void
    {
        $r = InvoiceCalculator::calculate([[
            'quantity' => '1', 'unit_price' => '500', 'discount_pct' => '0', 'tax_object_code' => '02',
            'tax' => ['tax_code' => '002', 'rate' => '0', 'is_exempt' => true],
        ]]);

        $this->assertSame('0.00', $r['tax_total']);
        $this->assertSame('500.00', $r['total']);
        $this->assertSame('Exento', $r['items'][0]['taxes'][0]['factor_type']);
        $this->assertNull($r['items'][0]['taxes'][0]['amount']);
    }

    public function test_not_tax_object_lines_have_no_tax_rows(): void
    {
        $r = InvoiceCalculator::calculate([[
            'quantity' => '1', 'unit_price' => '500', 'discount_pct' => '0', 'tax_object_code' => '01', 'tax' => $this->iva(),
        ]]);

        $this->assertSame([], $r['items'][0]['taxes']);
        $this->assertSame('500.00', $r['total']);
    }

    public function test_rounding_is_half_up_per_line(): void
    {
        // 3 x 0.335 = 1.005 -> 1.01 (half-up), IVA 1.01 x 16% = 0.1616 -> 0.16
        $r = InvoiceCalculator::calculate([[
            'quantity' => '3', 'unit_price' => '0.335', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax' => $this->iva(),
        ]]);

        $this->assertSame('1.01', $r['subtotal']);
        $this->assertSame('0.16', $r['tax_total']);
        $this->assertSame('1.17', $r['total']);
    }

    public function test_totals_are_the_sum_of_rounded_lines(): void
    {
        $r = InvoiceCalculator::calculate([
            ['quantity' => '1', 'unit_price' => '100.10', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax' => $this->iva()],
            ['quantity' => '1', 'unit_price' => '100.10', 'discount_pct' => '0', 'tax_object_code' => '02', 'tax' => $this->iva()],
        ]);

        // IVA por concepto: 16.016 -> 16.02 (x2) = 32.04
        $this->assertSame('200.20', $r['subtotal']);
        $this->assertSame('32.04', $r['tax_total']);
        $this->assertSame('232.24', $r['total']);
    }
}

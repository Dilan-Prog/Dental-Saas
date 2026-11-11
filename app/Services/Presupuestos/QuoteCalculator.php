<?php

namespace App\Services\Presupuestos;

use App\Support\Money;

/**
 * Cálculo de partidas y totales de un presupuesto con aritmética decimal exacta
 * (bcmath, redondeo half-up a 2 decimales por concepto):
 *
 *   subtotal  = cantidad x precio unitario
 *   descuento = subtotal x desc% / 100
 *   impuesto  = (subtotal - descuento) x tasa      (0 si es exento)
 *   total     = subtotal - descuento + impuesto
 *
 * Los totales del presupuesto son la suma de las partidas, de modo que
 * total = subtotal - discount_total + tax_total siempre se cumple exactamente.
 */
class QuoteCalculator
{
    /**
     * @return array{line_subtotal: string, line_discount: string, line_tax: string, line_total: string}
     */
    public function line(string|int|float $quantity, string|int|float $unitPrice, string|int|float $discountPct, string|int|float $taxRate, bool $exempt): array
    {
        $subtotal = Money::mul($quantity, $unitPrice);
        $discount = Money::percent($subtotal, $discountPct);
        $base = Money::sub($subtotal, $discount);
        $tax = $exempt ? '0.00' : Money::mul($base, $taxRate);

        return [
            'line_subtotal' => $subtotal,
            'line_discount' => $discount,
            'line_tax' => $tax,
            'line_total' => Money::add($base, $tax),
        ];
    }

    /**
     * @param  iterable<array{line_subtotal: string, line_discount: string, line_tax: string}>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public function totals(iterable $lines): array
    {
        $lines = collect($lines);

        $subtotal = Money::sum($lines->pluck('line_subtotal'));
        $discount = Money::sum($lines->pluck('line_discount'));
        $tax = Money::sum($lines->pluck('line_tax'));

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => Money::add(Money::sub($subtotal, $discount), $tax),
        ];
    }
}

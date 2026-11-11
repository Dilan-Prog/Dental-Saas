<?php

namespace App\Services\Facturacion;

use App\Support\Money;

/**
 * Calculo de partidas e impuestos de una factura (CFDI 4.0) con aritmetica
 * decimal (Money) y redondeo half-up a 2 decimales por concepto.
 *
 *   importe   = cantidad x precio unitario
 *   descuento = importe x % descuento
 *   base      = importe - descuento
 *   IVA       = base x tasa   (solo con ObjetoImp 02/03 y perfil con tasa)
 *   total     = subtotal - descuento + impuestos trasladados
 */
class InvoiceCalculator
{
    /**
     * @param  array<int, array<string,mixed>>  $lines  cada una: quantity, unit_price, discount_pct, tax_object_code,
     *                                                  tax {tax_code, rate, is_exempt}|null
     * @return array{items: array<int, array<string,mixed>>, subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public static function calculate(array $lines): array
    {
        $items = [];
        $subtotal = '0.00';
        $discountTotal = '0.00';
        $taxTotal = '0.00';

        foreach (array_values($lines) as $i => $line) {
            $amount = Money::mul($line['quantity'] ?? 0, $line['unit_price'] ?? 0);
            $discount = Money::percent($amount, $line['discount_pct'] ?? 0);
            if (Money::cmp($discount, $amount) > 0) {
                $discount = $amount;
            }
            $base = Money::sub($amount, $discount);

            $objeto = (string) ($line['tax_object_code'] ?? '02');
            $taxes = [];
            $lineTax = '0.00';

            if (in_array($objeto, ['02', '03'], true) && ! empty($line['tax'])) {
                $tax = $line['tax'];
                $code = (string) ($tax['tax_code'] ?? '002');
                if (! empty($tax['is_exempt'])) {
                    $taxes[] = [
                        'tax_code' => $code, 'factor_type' => 'Exento', 'rate' => null,
                        'base' => $base, 'amount' => null, 'is_withholding' => false,
                    ];
                } else {
                    $rate = (string) ($tax['rate'] ?? '0');
                    $amountTax = Money::mul($base, $rate);
                    $taxes[] = [
                        'tax_code' => $code, 'factor_type' => 'Tasa', 'rate' => $rate,
                        'base' => $base, 'amount' => $amountTax, 'is_withholding' => false,
                    ];
                    $lineTax = $amountTax;
                }
            }

            $items[] = [
                'line_number' => $i + 1,
                'amount' => $amount,
                'discount' => $discount,
                'base' => $base,
                'tax' => $lineTax,
                'taxes' => $taxes,
            ];

            $subtotal = Money::add($subtotal, $amount);
            $discountTotal = Money::add($discountTotal, $discount);
            $taxTotal = Money::add($taxTotal, $lineTax);
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => Money::add(Money::sub($subtotal, $discountTotal), $taxTotal),
        ];
    }
}

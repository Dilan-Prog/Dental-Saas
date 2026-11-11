<?php

namespace App\Services\Facturacion;

use App\Enums\CfdiType;
use App\Enums\FiscalStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Support\Money;
use Carbon\Carbon;

/** KPIs de facturacion: facturado del mes, por cobrar y cobrado del mes. */
class BillingMetrics
{
    /** Estados con efecto fiscal vigente (excluye borrador, error y cancelada). */
    public const ACTIVE_STATES = [
        FiscalStatus::PendienteTimbrado,
        FiscalStatus::Timbrada,
        FiscalStatus::CancelacionSolicitada,
    ];

    /** @return array{invoiced:string, receivable:string, receivable_count:int, collected:string} */
    public static function summary(?Carbon $month = null): array
    {
        $month ??= now();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $states = array_map(fn ($s) => $s->value, self::ACTIVE_STATES);

        $invoiced = Invoice::query()
            ->where('cfdi_type', CfdiType::Ingreso->value)
            ->whereIn('fiscal_status', $states)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total');

        // Misma definición que el Dashboard (Invoice::receivable): sin borradores ni canceladas.
        $receivable = Invoice::receivable()
            ->where('cfdi_type', CfdiType::Ingreso->value);
        $receivableTotal = (clone $receivable)->sum('total');
        $applied = PaymentApplication::query()->whereNull('reversed_at')
            ->whereIn('invoice_id', (clone $receivable)->select('id'))->sum('applied_amount');

        $collected = Payment::query()
            ->where('status', PaymentStatus::Registrado->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        return [
            'invoiced' => Money::round($invoiced),
            'receivable' => Money::sub($receivableTotal, $applied),
            'receivable_count' => (clone $receivable)->count(),
            'collected' => Money::round($collected),
        ];
    }
}

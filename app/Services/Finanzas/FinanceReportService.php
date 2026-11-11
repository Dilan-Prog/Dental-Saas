<?php

namespace App\Services\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Models\FinancialMovement;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del flujo de efectivo operativo (NO contabilidad formal).
 * Los KPI y graficas usan solo movimientos "efectivos" (sin revertidos ni sus reversiones).
 * Dependen unicamente del periodo y la sucursal; la tabla admite filtros adicionales.
 */
class FinanceReportService
{
    public const PRESETS = [
        'month' => 'Este mes',
        'last_month' => 'Mes anterior',
        'quarter' => 'Trimestre',
        'year' => 'Año',
        'custom' => 'Personalizado',
    ];

    /** Paleta (variables CSS definidas en finanzas.css). */
    public const PALETTE = ['var(--fin-c1)', 'var(--fin-c2)', 'var(--fin-c3)', 'var(--fin-c4)', 'var(--fin-c5)', 'var(--fin-c6)', 'var(--fin-c7)', 'var(--fin-c8)'];

    /**
     * Normaliza los filtros de la peticion. Devuelve fechas Carbon y strings limpios.
     *
     * @param  array<string, mixed>  $input
     * @return array{preset:string, from:Carbon, to:Carbon, category_id:?string, method:?string, direction:?string, branch_id:?string, q:?string}
     */
    public function filters(array $input): array
    {
        $today = now()->startOfDay();
        $preset = $input['preset'] ?? (isset($input['from']) || isset($input['to']) ? 'custom' : 'month');

        [$from, $to] = match ($preset) {
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'quarter' => [$today->copy()->startOfQuarter(), $today->copy()->endOfQuarter()->startOfDay()],
            'year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()->startOfDay()],
            'custom' => [
                isset($input['from']) ? Carbon::parse($input['from'])->startOfDay() : $today->copy()->startOfMonth(),
                isset($input['to']) ? Carbon::parse($input['to'])->startOfDay() : $today->copy()->endOfMonth()->startOfDay(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'preset' => array_key_exists($preset, self::PRESETS) ? $preset : 'month',
            'from' => $from,
            'to' => $to,
            'category_id' => $input['category_id'] ?? null,
            'method' => $input['method'] ?? null,
            'direction' => $input['direction'] ?? null,
            'branch_id' => $input['branch_id'] ?? null,
            'q' => isset($input['q']) && trim((string) $input['q']) !== '' ? trim((string) $input['q']) : null,
        ];
    }

    /** Consulta del listado de transacciones (incluye revertidas y reversiones, con todos los filtros). */
    public function listQuery(array $f): Builder
    {
        $query = FinancialMovement::query()
            ->with(['category', 'branch', 'reversal', 'cashSession.cashRegister'])
            ->whereBetween('occurred_on', [$f['from']->toDateString(), $f['to']->toDateString()]);

        if ($f['category_id']) {
            $query->where('category_id', $f['category_id']);
        }
        if ($f['method']) {
            $query->where('method', $f['method']);
        }
        if ($f['direction']) {
            $query->where('direction', $f['direction']);
        }
        if ($f['branch_id']) {
            $query->where('branch_id', $f['branch_id']);
        }
        if ($f['q']) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $query->where('concept', 'like', $like);
        }

        return $query->orderByDesc('occurred_on')->orderByDesc('created_at');
    }

    /** Base de los reportes: solo efectivos, por periodo y sucursal. */
    private function effective(Carbon $from, Carbon $to, ?string $branchId): Builder
    {
        $query = FinancialMovement::query()->effective()
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /** @return array{income:string, expense:string, profit:string} */
    public function totals(Carbon $from, Carbon $to, ?string $branchId = null): array
    {
        $rows = $this->effective($from, $to, $branchId)
            ->toBase()
            ->select('direction', DB::raw('SUM(amount) as total'))
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $income = Money::round($rows[MovementDirection::Ingreso->value] ?? 0);
        $expense = Money::round($rows[MovementDirection::Egreso->value] ?? 0);

        return ['income' => $income, 'expense' => $expense, 'profit' => Money::sub($income, $expense)];
    }

    /**
     * KPI del periodo con comparacion contra el periodo anterior de igual duracion.
     *
     * @param  array<string, mixed>  $f
     */
    public function kpis(array $f): array
    {
        $current = $this->totals($f['from'], $f['to'], $f['branch_id']);

        [$prevFrom, $prevTo] = $this->previousPeriod($f['preset'], $f['from'], $f['to']);
        $previous = $this->totals($prevFrom, $prevTo, $f['branch_id']);

        $margin = Money::cmp($current['income'], '0') > 0
            ? (float) bcdiv(bcmul($current['profit'], '100', 4), $current['income'], 1)
            : null;

        return [
            'income' => $current['income'],
            'expense' => $current['expense'],
            'profit' => $current['profit'],
            'margin' => $margin,
            'income_delta' => $this->pctChange($current['income'], $previous['income']),
            'expense_delta' => $this->pctChange($current['expense'], $previous['expense']),
            'previous' => $previous,
            'previous_from' => $prevFrom,
            'previous_to' => $prevTo,
        ];
    }

    /**
     * Periodo con el que se compara: el mes/trimestre/año calendario anterior para
     * los atajos, o los N dias inmediatamente previos para un rango personalizado.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousPeriod(string $preset, Carbon $from, Carbon $to): array
    {
        switch ($preset) {
            case 'month':
            case 'last_month':
                $prevFrom = $from->copy()->subMonthNoOverflow()->startOfMonth();

                return [$prevFrom, $prevFrom->copy()->endOfMonth()->startOfDay()];
            case 'quarter':
                $prevFrom = $from->copy()->subMonthsNoOverflow(3)->startOfQuarter();

                return [$prevFrom, $prevFrom->copy()->endOfQuarter()->startOfDay()];
            case 'year':
                $prevFrom = $from->copy()->subYear()->startOfYear();

                return [$prevFrom, $prevFrom->copy()->endOfYear()->startOfDay()];
        }

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();

        return [$prevTo->copy()->subDays($days - 1), $prevTo];
    }

    /** Variacion porcentual; null si no hay base de comparacion. */
    public function pctChange(string $current, string $previous): ?float
    {
        if (Money::cmp($previous, '0') === 0) {
            return null;
        }

        return round((float) bcdiv(bcmul(Money::sub($current, $previous), '100', 4), $previous, 2), 1);
    }

    /**
     * Egresos efectivos del periodo por categoria con su porcentaje.
     *
     * @return Collection<int, array{name:string, total:string, percent:float, color:string}>
     */
    public function expenseByCategory(Carbon $from, Carbon $to, ?string $branchId = null): Collection
    {
        $rows = $this->effective($from, $to, $branchId)
            ->where('financial_movements.direction', MovementDirection::Egreso->value)
            ->join('financial_categories as fc', 'fc.id', '=', 'financial_movements.category_id')
            ->toBase()
            ->select('fc.name as name', DB::raw('SUM(financial_movements.amount) as total'))
            ->groupBy('fc.id', 'fc.name')
            ->orderByDesc('total')
            ->get();

        $sum = Money::sum($rows->pluck('total'));

        return $rows->values()->map(function ($row, $i) use ($sum) {
            return [
                'name' => $row->name,
                'total' => Money::round($row->total),
                'percent' => Money::cmp($sum, '0') > 0 ? round((float) bcdiv(bcmul((string) $row->total, '100', 4), $sum, 2), 1) : 0.0,
                'color' => self::PALETTE[$i % count(self::PALETTE)],
            ];
        });
    }

    /**
     * Serie mensual (ingresos vs egresos) de los ultimos $months meses que terminan en el mes de $end.
     *
     * @return array<int, array{label:string, income:string, expense:string, income_pct:float, expense_pct:float}>
     */
    public function monthlySeries(Carbon $end, int $months = 6, ?string $branchId = null): array
    {
        $endMonth = CarbonImmutable::parse($end)->startOfMonth();
        $start = $endMonth->subMonths($months - 1);

        $rows = $this->effective($start->toMutable(), $endMonth->endOfMonth()->toMutable(), $branchId)
            ->toBase()
            ->select(DB::raw("DATE_FORMAT(occurred_on, '%Y-%m') as ym"), 'direction', DB::raw('SUM(amount) as total'))
            ->groupBy('ym', 'direction')
            ->get()
            ->groupBy('ym');

        $series = [];
        $max = '0';
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $bucket = $rows->get($month->format('Y-m'), collect())->pluck('total', 'direction');
            $income = Money::round($bucket[MovementDirection::Ingreso->value] ?? 0);
            $expense = Money::round($bucket[MovementDirection::Egreso->value] ?? 0);
            $max = Money::cmp($income, $max) > 0 ? $income : $max;
            $max = Money::cmp($expense, $max) > 0 ? $expense : $max;

            $series[] = ['label' => FinanceFormatter::monthShort($month), 'income' => $income, 'expense' => $expense, 'month' => $month->format('Y-m')];
        }

        foreach ($series as &$point) {
            $point['income_pct'] = Money::cmp($max, '0') > 0 ? round((float) bcdiv(bcmul($point['income'], '100', 4), $max, 2), 1) : 0.0;
            $point['expense_pct'] = Money::cmp($max, '0') > 0 ? round((float) bcdiv(bcmul($point['expense'], '100', 4), $max, 2), 1) : 0.0;
        }

        return $series;
    }

    public function methodOptions(): array
    {
        return PaymentMethod::options();
    }
}

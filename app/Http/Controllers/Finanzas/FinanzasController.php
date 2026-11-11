<?php

namespace App\Http\Controllers\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finanzas\FinanzasFilterRequest;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Services\Finanzas\CashSessionService;
use App\Services\Finanzas\FinanceFormatter;
use App\Services\Finanzas\FinanceReportService;
use App\Services\Finanzas\MovementService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanzasController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $report,
        private readonly CashSessionService $cash,
    ) {
    }

    /** Resumen: KPI, graficas, filtros y listado de transacciones. */
    public function index(FinanzasFilterRequest $request): View
    {
        $this->cash->ensureDefaultRegister();

        $filters = $request->reportFilters();
        $kpis = $this->report->kpis($filters);
        $cashOnHand = $this->cash->cashOnHand();

        return view('finanzas.index', [
            'filters' => $filters,
            'presets' => FinanceReportService::PRESETS,
            'periodLabel' => $this->periodLabel($filters),
            'kpis' => $kpis,
            'expenseByCategory' => $this->report->expenseByCategory($filters['from'], $filters['to'], $filters['branch_id']),
            'series' => $this->report->monthlySeries($filters['to'], 6, $filters['branch_id']),
            'cashOnHand' => $cashOnHand,
            'movements' => $this->report->listQuery($filters)->paginate(15)->withQueryString(),
            'categories' => FinancialCategory::query()->active()->orderBy('direction')->orderBy('name')->get(),
            'branches' => Branch::query()->where('status', 'active')->orderByDesc('is_main')->orderBy('name')->get(),
            'registers' => CashRegister::query()->where('status', RecordStatus::Active->value)->with('branch')->orderBy('name')->get(),
            'openSessions' => $cashOnHand['sessions'],
            'methods' => PaymentMethod::options(),
            'directions' => MovementDirection::options(),
            'systemCategoryNames' => array_values(MovementService::REVERSAL_CATEGORY),
        ]);
    }

    /** Exporta a CSV las transacciones del periodo con los mismos filtros (permiso exportar). */
    public function export(FinanzasFilterRequest $request): StreamedResponse
    {
        $filters = $request->reportFilters();
        $query = $this->report->listQuery($filters)->with('creator');

        $filename = 'finanzas_'.$filters['from']->format('Ymd').'_'.$filters['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete los acentos
            fputcsv($out, ['Fecha', 'Tipo', 'Concepto', 'Categoría', 'Método', 'Monto', 'Moneda', 'Sucursal', 'Origen', 'Estado', 'Registrado por']);

            $query->lazy(500)->each(function (FinancialMovement $m) use ($out) {
                $income = $m->direction === MovementDirection::Ingreso;
                fputcsv($out, array_map([$this, 'csvCell'], [
                    $m->occurred_on->format('Y-m-d'),
                    $m->direction->label(),
                    $m->concept,
                    $m->category?->name ?? '',
                    $m->method->label(),
                    ($income ? '' : '-').Money::round($m->amount),
                    $m->currency,
                    $m->branch?->name ?? '',
                    $m->payment_id ? 'Pago de paciente' : ($m->reversed_of_id ? 'Reversión' : 'Manual'),
                    $m->reversed_of_id ? 'Reversión' : ($m->relationLoaded('reversal') && $m->reversal ? 'Revertido' : 'Vigente'),
                    $m->creator?->full_name ?? '',
                ]));
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Evita inyeccion de formulas al abrir el CSV en Excel / Sheets. */
    public function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text)
            ? "'".$text
            : $text;
    }

    /** @param array<string, mixed> $f */
    private function periodLabel(array $f): string
    {
        if ($f['from']->isSameMonth($f['to']) && $f['from']->day === 1 && $f['to']->isSameDay($f['to']->copy()->endOfMonth())) {
            return FinanceFormatter::month($f['from']);
        }

        return FinanceFormatter::date($f['from']).' – '.FinanceFormatter::date($f['to']);
    }
}

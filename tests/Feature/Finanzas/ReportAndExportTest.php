<?php

namespace Tests\Feature\Finanzas;

use App\Services\Finanzas\FinanceReportService;

class ReportAndExportTest extends FinanzasTestCase
{
    public function test_kpis_and_category_breakdown_are_coherent_with_the_movements(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $rent = $this->category('Renta', 'egreso');
        $supplies = $this->category('Insumos', 'egreso');

        $this->makeMovement($income, ['amount' => '1000.00']);
        $this->makeMovement($income, ['amount' => '500.00', 'method' => 'efectivo']);
        $this->makeMovement($rent, ['amount' => '300.00']);
        $this->makeMovement($supplies, ['amount' => '100.00']);

        $response = $this->get(route('finanzas.index'))->assertOk();
        $kpis = $response->viewData('kpis');

        $this->assertSame('1500.00', $kpis['income']);
        $this->assertSame('400.00', $kpis['expense']);
        $this->assertSame('1100.00', $kpis['profit']);
        $this->assertSame(73.3, $kpis['margin']);

        $breakdown = $response->viewData('expenseByCategory');
        $this->assertSame(['Renta', 'Insumos'], $breakdown->pluck('name')->all());
        $this->assertSame(75.0, $breakdown[0]['percent']);
        $this->assertSame(25.0, $breakdown[1]['percent']);
        $this->assertEqualsWithDelta(100.0, $breakdown->sum('percent'), 0.1);
    }

    public function test_reports_ignore_other_periods_and_respect_the_range(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $this->makeMovement($income, ['amount' => '700.00']);
        $this->makeMovement($income, ['amount' => '9999.00', 'occurred_on' => now()->subMonths(3)->toDateString()]);

        $this->assertSame('700.00', $this->get(route('finanzas.index'))->viewData('kpis')['income']);

        $from = now()->subMonths(4)->toDateString();
        $to = now()->subMonths(2)->toDateString();
        $custom = $this->get(route('finanzas.index', ['from' => $from, 'to' => $to]))->viewData('kpis');
        $this->assertSame('9999.00', $custom['income']);
    }

    public function test_period_comparison_against_the_previous_period(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $f = app(FinanceReportService::class);
        $this->makeMovement($income, ['amount' => '200.00', 'occurred_on' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $this->makeMovement($income, ['amount' => '300.00']);

        $kpis = $f->kpis($f->filters([]));

        $this->assertSame('300.00', $kpis['income']);
        $this->assertSame('200.00', $kpis['previous']['income']);
        $this->assertSame(50.0, $kpis['income_delta']);
        $this->assertNull($f->pctChange('10.00', '0.00'));
    }

    public function test_monthly_series_returns_six_months_ending_in_the_selected_month(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $expense = $this->category('Renta', 'egreso');
        $this->makeMovement($income, ['amount' => '1000.00']);
        $this->makeMovement($expense, ['amount' => '500.00']);

        $series = app(FinanceReportService::class)->monthlySeries(now(), 6);

        $this->assertCount(6, $series);
        $last = end($series);
        $this->assertSame('1000.00', $last['income']);
        $this->assertSame('500.00', $last['expense']);
        $this->assertSame(100.0, $last['income_pct']);
        $this->assertSame(50.0, $last['expense_pct']);
        $this->assertSame('0.00', $series[0]['income']);
    }

    public function test_transaction_list_filters(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $rent = $this->category('Renta', 'egreso');
        $this->makeMovement($income, ['concept' => 'Pago de ortodoncia', 'method' => 'tarjeta']);
        $this->makeMovement($rent, ['concept' => 'Renta consultorio', 'method' => 'transferencia']);

        $list = fn (array $q) => $this->get(route('finanzas.index', $q))->viewData('movements')->pluck('concept')->all();

        $this->assertCount(2, $list([]));
        $this->assertSame(['Renta consultorio'], $list(['direction' => 'egreso']));
        $this->assertSame(['Pago de ortodoncia'], $list(['category_id' => $income->id]));
        $this->assertSame(['Renta consultorio'], $list(['method' => 'transferencia']));
        $this->assertSame(['Pago de ortodoncia'], $list(['q' => 'ortodon']));
        $this->assertSame([], $list(['q' => '100%']));
    }

    public function test_pagination_keeps_filters(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        foreach (range(1, 17) as $i) {
            $this->makeMovement($cat, ['concept' => 'Compra '.$i]);
        }

        $page = $this->get(route('finanzas.index', ['direction' => 'egreso']))->viewData('movements');

        $this->assertSame(15, $page->count());
        $this->assertSame(17, $page->total());
        $this->assertStringContainsString('direction=egreso', $page->nextPageUrl());
    }

    public function test_csv_export_respects_filters_and_neutralizes_formulas(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $expense = $this->category('Insumos', 'egreso');
        $this->makeMovement($income, ['concept' => '=SUM(1+1)', 'amount' => '1500.00']);
        $this->makeMovement($expense, ['concept' => 'Compra de guantes', 'amount' => '240.50']);

        $csv = $this->get(route('finanzas.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Fecha,Tipo,Concepto,Categoría,Método,Monto', $csv);
        $this->assertStringContainsString("'=SUM(1+1)", $csv);
        $this->assertStringContainsString('1500.00', $csv);
        $this->assertStringContainsString('-240.50', $csv);

        $onlyExpenses = $this->get(route('finanzas.export', ['direction' => 'egreso']))->streamedContent();
        $this->assertStringContainsString('Compra de guantes', $onlyExpenses);
        $this->assertStringNotContainsString('SUM(1+1)', $onlyExpenses);
    }

    public function test_export_marks_reversals_and_origin(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $original = $this->makeMovement($cat, ['concept' => 'Compra duplicada']);
        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Captura duplicada']);

        $csv = $this->get(route('finanzas.export'))->streamedContent();

        $this->assertStringContainsString('Revertido', $csv);
        $this->assertStringContainsString('Reversión', $csv);
    }
}

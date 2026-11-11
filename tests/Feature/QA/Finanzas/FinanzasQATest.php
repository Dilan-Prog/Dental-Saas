<?php

namespace Tests\Feature\QA\Finanzas;

use App\Models\FinancialMovement;
use App\Services\Finanzas\CashSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Finanzas\FinanzasTestCase;

/** QA4: validaciones y coherencia de Finanzas. */
class FinanzasQATest extends FinanzasTestCase
{
    /** @dataProvider badAmounts */
    public function test_manual_movement_rejects_bad_amounts(mixed $amount): void
    {
        $cat = $this->category('Insumos', 'egreso');

        $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['amount' => $amount]))
            ->assertSessionHasErrorsIn('movement', 'amount');

        $this->assertSame(0, FinancialMovement::query()->count());
    }

    public static function badAmounts(): array
    {
        return [
            'vacio' => [''], 'cero' => ['0'], 'cero decimal' => ['0.00'], 'negativo' => ['-5'],
            'cientifica' => ['1e3'], 'tres decimales' => ['1.234'], 'texto' => ['abc'],
            'enorme' => ['100000000000'], 'infinito' => ['INF'], 'hex' => ['0x10'],
            'array' => [['1']], 'solo signo' => ['-'], 'punto' => ['.'],
            'coma decimal' => ['12,50'], 'coma decimal corta' => ['1,5'], 'miles mal agrupados' => ['1,00'],
        ];
    }

    public function test_manual_movement_accepts_money_formats(): void
    {
        $cat = $this->category('Insumos', 'egreso');

        $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['amount' => '$1,250.50']))
            ->assertSessionHasNoErrors();

        $this->assertSame('1250.50', (string) FinancialMovement::query()->first()->amount);
    }

    public function test_concept_validation(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        foreach (['', '  ', 'ab', str_repeat('a', 301)] as $concept) {
            $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['concept' => $concept]))
                ->assertSessionHasErrorsIn('movement', 'concept');
        }
        $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['concept' => str_repeat('a', 300)]))
            ->assertSessionHasNoErrors();
    }

    public function test_category_must_match_direction_and_exist(): void
    {
        $egreso = $this->category('Insumos', 'egreso');
        $ingreso = $this->category('Consultas', 'ingreso');
        $inactive = $this->category('Vieja', 'egreso', 'inactive');

        $this->post(route('finanzas.movimientos.store'), $this->payload($egreso, ['direction' => 'ingreso']))
            ->assertSessionHasErrorsIn('movement', 'category_id');
        $this->post(route('finanzas.movimientos.store'), $this->payload($ingreso, ['direction' => 'egreso']))
            ->assertSessionHasErrorsIn('movement', 'category_id');
        $this->post(route('finanzas.movimientos.store'), $this->payload($inactive))
            ->assertSessionHasErrorsIn('movement', 'category_id');
        $this->post(route('finanzas.movimientos.store'), $this->payload($egreso, ['category_id' => (string) Str::uuid()]))
            ->assertSessionHasErrorsIn('movement', 'category_id');
        $this->post(route('finanzas.movimientos.store'), $this->payload($egreso, ['category_id' => 'no-uuid']))
            ->assertSessionHasErrorsIn('movement', 'category_id');
        $this->post(route('finanzas.movimientos.store'), $this->payload($egreso, ['direction' => 'otro']))
            ->assertSessionHasErrorsIn('movement', 'direction');
        $this->post(route('finanzas.movimientos.store'), $this->payload($egreso, ['method' => 'bitcoin']))
            ->assertSessionHasErrorsIn('movement', 'method');
        $this->assertSame(0, FinancialMovement::query()->count());
    }

    public function test_dates(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        foreach ([now()->addDay()->toDateString(), '1999-12-31', '2026-13-45', 'ayer', '', '2026/01/01', '01-01-2026'] as $d) {
            $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['occurred_on' => $d]))
                ->assertSessionHasErrorsIn('movement', 'occurred_on');
        }
        $this->assertSame(0, FinancialMovement::query()->count());
    }

    public function test_cash_movement_without_open_session_fails_and_keeps_input(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $this->cashRegister();

        $this->from(route('finanzas.index'))
            ->post(route('finanzas.movimientos.store'), $this->payload($cat, ['method' => 'efectivo']))
            ->assertSessionHasErrorsIn('movement')
            ->assertRedirect(route('finanzas.index'))
            ->assertSessionHasInput('concept', 'Compra de material');

        $this->assertSame(0, FinancialMovement::query()->count());
    }

    public function test_cash_expense_cannot_exceed_cash_on_hand(): void
    {
        $this->openCash('100.00');
        $cat = $this->category('Insumos', 'egreso');

        $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['method' => 'efectivo', 'amount' => '100.01']))
            ->assertSessionHasErrorsIn('movement', 'amount');
        $this->post(route('finanzas.movimientos.store'), $this->payload($cat, ['method' => 'efectivo', 'amount' => '100.00']))
            ->assertSessionHasNoErrors();
    }

    public function test_duplicate_submission_key_is_rejected(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $payload = $this->payload($cat);

        $this->post(route('finanzas.movimientos.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('finanzas.movimientos.store'), $payload)->assertSessionHasErrorsIn('movement');

        $this->assertSame(1, FinancialMovement::query()->count());
    }

    public function test_reversal_flow_and_effective_scope(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $m = $this->makeMovement($cat, ['amount' => '300.00']);

        $this->post(route('finanzas.movimientos.reverse', $m), ['reason' => 'Error de captura'])->assertSessionHasNoErrors();
        $this->assertSame(2, FinancialMovement::query()->count());
        $this->assertSame(0, FinancialMovement::query()->effective()->count());

        $this->post(route('finanzas.movimientos.reverse', $m), ['reason' => 'Otra vez mas'])->assertSessionHasErrorsIn('reverse');
        $rev = FinancialMovement::query()->whereNotNull('reversed_of_id')->first();
        $this->post(route('finanzas.movimientos.reverse', $rev), ['reason' => 'Revertir reversion'])->assertSessionHasErrorsIn('reverse');
        $this->assertSame(2, FinancialMovement::query()->count());

        $m2 = $this->makeMovement($cat);
        foreach (['', 'abc', str_repeat('x', 201)] as $reason) {
            $this->post(route('finanzas.movimientos.reverse', $m2), ['reason' => $reason])->assertSessionHasErrorsIn('reverse', 'reason');
        }
    }

    public function test_payment_movement_cannot_be_reversed_from_finanzas(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $m = $this->makePaymentMovement($income);

        $this->post(route('finanzas.movimientos.reverse', $m), ['reason' => 'Quiero revertir'])->assertSessionHasErrorsIn('reverse');
        $this->assertSame(1, FinancialMovement::query()->count());
    }

    public function test_one_movement_per_payment_is_enforced_by_database(): void
    {
        $income = $this->category('Tratamientos', 'ingreso');
        $m = $this->makePaymentMovement($income);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeMovement($income, ['payment_id' => $m->payment_id]);
    }

    public function test_cash_session_rules(): void
    {
        $register = $this->cashRegister();
        $session = app(CashSessionService::class)->open($register, '500.00', $this->admin);

        $this->post(route('finanzas.turnos.open'), ['cash_register_id' => $register->id, 'opening_amount' => '10'])
            ->assertSessionHasErrorsIn('openCash');
        foreach (['-1', 'abc', '1e2', '1.234'] as $a) {
            $this->post(route('finanzas.turnos.open'), ['cash_register_id' => $register->id, 'opening_amount' => $a])
                ->assertSessionHasErrorsIn('openCash', 'opening_amount');
        }
        // Un solo mensaje por campo (bail): 'abc' no debe mostrar ademas el de decimales.
        $this->post(route('finanzas.turnos.open'), ['cash_register_id' => $register->id, 'opening_amount' => 'abc']);
        $this->assertCount(1, session('errors')->getBag('openCash')->get('opening_amount'));
        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '5', 'reason' => 'Ajuste de prueba'])
            ->assertSessionHasErrorsIn('adjust');

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '480.00'])->assertSessionHasErrorsIn('closeCash', 'notes');
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '-1'])->assertSessionHasErrorsIn('closeCash', 'counted_cash');
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '480.00', 'notes' => 'Faltante de 20'])->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame('cerrada', $session->status->value);
        $this->assertSame('-20.00', (string) $session->difference);
        $this->assertSame('500.00', (string) $session->expected_cash);

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '500', 'notes' => 'x'])->assertSessionHasErrorsIn('closeCash');

        try {
            DB::table('cash_sessions')->where('id', $session->id)->update(['counted_cash' => 1]);
            $this->fail('Una sesion cerrada no debe poder modificarse');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }

        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '0', 'reason' => 'Ajuste de prueba'])->assertSessionHasErrorsIn('adjust', 'amount');
        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '20', 'reason' => ''])->assertSessionHasErrorsIn('adjust', 'reason');
        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '-20', 'reason' => 'Faltante justificado'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', app(CashSessionService::class)->adjustedDifference($session->fresh()));
    }

    public function test_expected_cash_formula_uses_exact_decimals(): void
    {
        $session = $this->openCash('0.10');
        $in = $this->category('Consultas', 'ingreso');
        $out = $this->category('Insumos', 'egreso');
        foreach (['0.20', '0.30', '0.10'] as $amt) {
            $this->makeMovement($in, ['method' => 'efectivo', 'amount' => $amt, 'cash_session_id' => $session->id]);
        }
        $this->makeMovement($out, ['method' => 'efectivo', 'amount' => '0.25', 'cash_session_id' => $session->id]);
        $this->makeMovement($in, ['method' => 'tarjeta', 'amount' => '999.00', 'cash_session_id' => $session->id]);

        $this->assertSame('0.45', $session->expectedCash());
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        foreach (['=HYPERLINK("http://x")', '+SUM(A1)', '-2+3', '@cmd', "\t=1+1", "\r=1+1"] as $c) {
            $this->makeMovement($cat, ['concept' => $c]);
        }
        $body = $this->get(route('finanzas.export', ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", $body), fn ($l) => trim($l) !== ''));
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+@\t\r]/', $cell, 'celda con formula: '.$cell);
                if ($cell !== '' && $cell[0] === '-' && ! is_numeric($cell)) {
                    $this->fail('celda con formula: '.$cell);
                }
            }
        }
    }

    public function test_register_cannot_be_deactivated_with_open_session(): void
    {
        $session = $this->openCash('10');
        $register = $session->cashRegister;

        $this->put(route('finanzas.cajas.update', $register), ['name' => $register->name, 'status' => 'inactive'])
            ->assertSessionHasErrors();
        $this->assertSame('active', $register->fresh()->status->value);
    }

    public function test_category_name_rules(): void
    {
        $this->category('Insumos', 'egreso');
        $this->post(route('finanzas.categorias.store'), ['name' => 'insumos', 'direction' => 'egreso'])->assertSessionHasErrorsIn('category', 'name');
        $this->post(route('finanzas.categorias.store'), ['name' => 'Insumos', 'direction' => 'ingreso'])->assertSessionHasNoErrors();
        $this->post(route('finanzas.categorias.store'), ['name' => 'Reversión de ingreso', 'direction' => 'egreso'])->assertSessionHasErrorsIn('category');
        $this->post(route('finanzas.categorias.store'), ['name' => 'X', 'direction' => 'egreso'])->assertSessionHasErrorsIn('category', 'name');
    }

    public function test_filters_with_garbage_never_cause_server_errors(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $this->makeMovement($cat);
        $bad = ['', 'abc', '2026-13-45', '9999-12-31', '0000-00-00', '-1', '%', "'; DROP TABLE financial_movements;--", '<script>', str_repeat('a', 400), '99999999999999999999'];

        foreach (['finanzas.index', 'finanzas.export', 'finanzas.cajas.index', 'finanzas.categorias.index'] as $route) {
            foreach ($bad as $v) {
                $r = $this->get(route($route, [
                    'from' => $v, 'to' => $v, 'category' => $v, 'category_id' => $v, 'method' => $v, 'branch' => $v, 'branch_id' => $v,
                    'direction' => $v, 'type' => $v, 'period' => $v, 'q' => $v, 'page' => $v, 'per_page' => $v, 'tab' => $v,
                ]));
                $this->assertLessThan(500, $r->getStatusCode(), $route.' devolvio '.$r->getStatusCode().' con '.$v);
            }
        }
        // Arreglos en lugar de texto.
        $r = $this->get(route('finanzas.index', ['from' => ['a'], 'category' => ['x'], 'q' => ['y']]));
        $this->assertLessThan(500, $r->getStatusCode());
        $this->assertSame(1, FinancialMovement::query()->count());
    }
}

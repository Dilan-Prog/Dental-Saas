<?php

namespace Tests\Feature\Finanzas;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Services\Finanzas\CashSessionService;
use Illuminate\Database\QueryException;

class CashSessionTest extends FinanzasTestCase
{
    private function openViaHttp(string $registerId, string $amount = '1000')
    {
        return $this->post(route('finanzas.turnos.open'), ['cash_register_id' => $registerId, 'opening_amount' => $amount]);
    }

    public function test_it_opens_a_session_with_an_opening_amount(): void
    {
        $register = $this->cashRegister();

        $this->openViaHttp($register->id, '$1,000.50')->assertSessionHasNoErrors()->assertSessionHas('success');

        $session = CashSession::firstOrFail();
        $this->assertSame(CashSessionStatus::Abierta, $session->status);
        $this->assertSame('1000.50', (string) $session->opening_amount);
        $this->assertSame($this->admin->id, $session->opened_by);
        $this->assertSame('1000.50', $session->expectedCash());
    }

    public function test_opening_amount_is_validated(): void
    {
        $register = $this->cashRegister();

        $this->openViaHttp($register->id, '-1')->assertSessionHasErrorsIn('openCash', ['opening_amount']);
        $this->openViaHttp($register->id, 'abc')->assertSessionHasErrorsIn('openCash', ['opening_amount']);
        $this->openViaHttp($register->id, '10.999')->assertSessionHasErrorsIn('openCash', ['opening_amount']);
        $this->post(route('finanzas.turnos.open'), [])->assertSessionHasErrorsIn('openCash', ['cash_register_id']);
        $this->openViaHttp((string) \Illuminate\Support\Str::uuid())->assertSessionHasErrorsIn('openCash', ['cash_register_id']);
        $this->assertSame(0, CashSession::count());

        // Un fondo de $0.00 es valido; vacio se toma como cero.
        $this->openViaHttp($register->id, '0')->assertSessionHasNoErrors();
    }

    public function test_only_one_open_session_per_register(): void
    {
        $register = $this->cashRegister();
        $this->openViaHttp($register->id)->assertSessionHasNoErrors();

        $this->openViaHttp($register->id)->assertSessionHasErrorsIn('openCash', ['cash_register_id']);

        $this->assertSame(1, CashSession::count());
    }

    public function test_inactive_registers_cannot_be_opened(): void
    {
        $register = $this->cashRegister();
        $register->update(['status' => 'inactive']);

        $this->openViaHttp($register->id)->assertSessionHasErrorsIn('openCash', ['cash_register_id']);
        $this->assertSame(0, CashSession::count());
    }

    public function test_database_blocks_a_second_open_session_even_bypassing_the_service(): void
    {
        $session = $this->openCash();

        $this->expectException(QueryException::class);
        CashSession::create([
            'cash_register_id' => $session->cash_register_id, 'opened_by' => $this->admin->id,
            'opened_at' => now(), 'opening_amount' => '0.00', 'status' => 'abierta',
        ]);
    }

    public function test_closing_computes_expected_and_difference_on_the_server(): void
    {
        $session = $this->openCash('1000.00');
        $income = $this->category('Otros ingresos', 'ingreso');
        $this->post(route('finanzas.movimientos.store'), $this->payload($income, ['method' => 'efectivo', 'amount' => '250.00']));

        // El cliente no puede imponer el esperado: solo manda lo contado.
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1250.00', 'expected_cash' => '9999'])
            ->assertSessionHasNoErrors();

        $closed = $session->fresh();
        $this->assertSame(CashSessionStatus::Cerrada, $closed->status);
        $this->assertSame('1250.00', (string) $closed->expected_cash);
        $this->assertSame('1250.00', (string) $closed->counted_cash);
        $this->assertSame('0.00', (string) $closed->difference);
        $this->assertSame($this->admin->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_difference_requires_notes(): void
    {
        $session = $this->openCash('1000.00');

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '980.00'])
            ->assertSessionHasErrorsIn('closeCash', ['notes']);
        $this->assertSame(CashSessionStatus::Abierta, $session->fresh()->status);

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '980.00', 'notes' => 'Faltante por cambio'])
            ->assertSessionHasNoErrors();
        $this->assertSame('-20.00', (string) $session->fresh()->difference);
    }

    public function test_counted_cash_is_validated(): void
    {
        $session = $this->openCash();

        $this->post(route('finanzas.turnos.close', $session), [])->assertSessionHasErrorsIn('closeCash', ['counted_cash']);
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '-5'])->assertSessionHasErrorsIn('closeCash', ['counted_cash']);
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => 'mil'])->assertSessionHasErrorsIn('closeCash', ['counted_cash']);
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1000', 'notes' => str_repeat('n', 1001)])->assertSessionHasErrorsIn('closeCash', ['notes']);
        $this->assertSame(CashSessionStatus::Abierta, $session->fresh()->status);
    }

    public function test_a_closed_session_cannot_be_closed_again_and_is_immutable(): void
    {
        $session = $this->openCash('1000.00');
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1000.00'])->assertSessionHasNoErrors();

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '5.00'])->assertSessionHasErrorsIn('closeCash', ['counted_cash']);
        $this->assertSame('1000.00', (string) $session->fresh()->counted_cash);

        // Aunque se salte la aplicacion, el trigger de la BD protege el turno cerrado.
        $this->expectException(QueryException::class);
        CashSession::query()->whereKey($session->id)->update(['counted_cash' => '1.00']);
    }

    public function test_cash_movements_cannot_be_added_to_a_closed_session(): void
    {
        $session = $this->openCash('1000.00');
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1000.00']);
        $income = $this->category('Otros ingresos', 'ingreso');

        $this->post(route('finanzas.movimientos.store'), $this->payload($income, ['method' => 'efectivo', 'cash_session_id' => $session->id]))
            ->assertSessionHasErrorsIn('movement');
        $this->assertSame(0, \App\Models\FinancialMovement::count());
    }

    public function test_adjustments_only_apply_to_closed_sessions_and_fix_the_final_difference(): void
    {
        $session = $this->openCash('1000.00');

        // En un turno abierto no se permiten ajustes.
        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '-20', 'reason' => 'Intento en turno abierto'])
            ->assertSessionHasErrorsIn('adjust', ['amount']);

        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '980.00', 'notes' => 'Faltante'])->assertSessionHasNoErrors();
        $service = app(CashSessionService::class);
        $this->assertSame('-20.00', $service->adjustedDifference($session->fresh()));

        $this->post(route('finanzas.turnos.adjust', $session), ['amount' => '-20', 'reason' => 'Propina no registrada'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_session_adjustments', ['cash_session_id' => $session->id, 'amount' => '-20.00', 'created_by' => $this->admin->id]);
        $this->assertSame('0.00', $service->adjustedDifference($session->fresh()));
        // El corte original no se toco.
        $this->assertSame('-20.00', (string) $session->fresh()->difference);
    }

    public function test_adjustment_validation(): void
    {
        $session = $this->openCash('1000.00');
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1000.00']);
        $url = route('finanzas.turnos.adjust', $session);

        $this->post($url, [])->assertSessionHasErrorsIn('adjust', ['amount', 'reason']);
        $this->post($url, ['amount' => '0', 'reason' => 'Motivo valido'])->assertSessionHasErrorsIn('adjust', ['amount']);
        $this->post($url, ['amount' => 'abc', 'reason' => 'Motivo valido'])->assertSessionHasErrorsIn('adjust', ['amount']);
        $this->post($url, ['amount' => '5.123', 'reason' => 'Motivo valido'])->assertSessionHasErrorsIn('adjust', ['amount']);
        $this->post($url, ['amount' => '10', 'reason' => 'abc'])->assertSessionHasErrorsIn('adjust', ['reason']);
        $this->assertDatabaseCount('cash_session_adjustments', 0);
    }

    public function test_cash_on_hand_kpi_sums_open_sessions(): void
    {
        $this->openCash('1000.00');
        $r2 = $this->cashRegister('Caja 2');
        app(CashSessionService::class)->open($r2, '500.00', $this->admin);

        $cash = app(CashSessionService::class)->cashOnHand();

        $this->assertSame('1500.00', $cash['amount']);
        $this->assertCount(2, $cash['sessions']);
        $this->get(route('finanzas.index'))->assertOk()->assertSee('2 turnos abiertos');
    }
}

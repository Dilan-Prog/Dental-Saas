<?php

namespace Tests\Feature\Finanzas;

use App\Models\FinancialMovement;
use App\Services\Finanzas\MovementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MovementTest extends FinanzasTestCase
{
    private function store(array $payload)
    {
        return $this->post(route('finanzas.movimientos.store'), $payload);
    }

    public function test_it_registers_a_manual_income_and_expense(): void
    {
        $income = $this->category('Otros ingresos', 'ingreso');
        $expense = $this->category('Insumos', 'egreso');

        $this->store($this->payload($income, ['concept' => '  Venta   de cepillos ', 'amount' => '$1,250.50']))
            ->assertRedirect(route('finanzas.index'))->assertSessionHas('success');
        $this->store($this->payload($expense, ['method' => 'transferencia', 'amount' => '300']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_movements', [
            'concept' => 'Venta de cepillos', 'amount' => '1250.50', 'direction' => 'ingreso',
            'currency' => 'MXN', 'created_by' => $this->admin->id, 'payment_id' => null, 'reversed_of_id' => null,
        ]);
        $this->assertDatabaseHas('financial_movements', ['direction' => 'egreso', 'amount' => '300.00', 'method' => 'transferencia']);
    }

    /** @dataProvider invalidAmounts */
    public function test_amount_is_validated(string $amount, string $expectedFragment): void
    {
        $cat = $this->category();

        $response = $this->store($this->payload($cat, ['amount' => $amount]));

        $response->assertSessionHasErrorsIn('movement', ['amount']);
        $this->assertStringContainsString($expectedFragment, session('errors')->getBag('movement')->first('amount'));
        $this->assertSame(0, FinancialMovement::count());
    }

    public static function invalidAmounts(): array
    {
        return [
            'cero' => ['0', 'mayor a cero'],
            'negativo' => ['-50', 'mayor a cero'],
            'texto' => ['abc', 'numerico'],
            'mas de dos decimales' => ['10.123', '2 decimales'],
            'demasiado grande' => ['100000000000.00', '99,999,999,999.99'],
        ];
    }

    public function test_required_fields_and_domain_rules(): void
    {
        $cat = $this->category();

        $this->store([])->assertSessionHasErrorsIn('movement', ['direction', 'category_id', 'method', 'concept', 'amount', 'occurred_on']);

        $this->store($this->payload($cat, ['concept' => 'ab']))->assertSessionHasErrorsIn('movement', ['concept']);
        $this->store($this->payload($cat, ['concept' => str_repeat('x', 301)]))->assertSessionHasErrorsIn('movement', ['concept']);
        $this->store($this->payload($cat, ['method' => 'cheque']))->assertSessionHasErrorsIn('movement', ['method']);
        $this->store($this->payload($cat, ['direction' => 'otro']))->assertSessionHasErrorsIn('movement', ['direction']);
        $this->store($this->payload($cat, ['branch_id' => (string) \Illuminate\Support\Str::uuid()]))->assertSessionHasErrorsIn('movement', ['branch_id']);
        $this->assertSame(0, FinancialMovement::count());
    }

    public function test_date_cannot_be_in_the_future_or_invalid(): void
    {
        $cat = $this->category();

        $this->store($this->payload($cat, ['occurred_on' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrorsIn('movement', ['occurred_on']);
        $this->store($this->payload($cat, ['occurred_on' => '31/12/2025']))->assertSessionHasErrorsIn('movement', ['occurred_on']);
        $this->store($this->payload($cat, ['occurred_on' => '1999-12-31']))->assertSessionHasErrorsIn('movement', ['occurred_on']);

        $this->store($this->payload($cat, ['occurred_on' => now()->subMonth()->toDateString()]))->assertSessionHasNoErrors();
    }

    public function test_category_must_match_direction_exist_and_be_active(): void
    {
        $income = $this->category('Otros ingresos', 'ingreso');
        $expense = $this->category('Insumos', 'egreso');
        $inactive = $this->category('Vieja', 'egreso', 'inactive');

        // egreso con categoria de ingreso
        $this->store($this->payload($income, ['direction' => 'egreso']))->assertSessionHasErrorsIn('movement', ['category_id']);
        // ingreso con categoria de egreso
        $this->store($this->payload($expense, ['direction' => 'ingreso']))->assertSessionHasErrorsIn('movement', ['category_id']);
        // categoria inactiva
        $this->store($this->payload($inactive))->assertSessionHasErrorsIn('movement', ['category_id']);
        // categoria inexistente
        $this->store($this->payload($expense, ['category_id' => (string) \Illuminate\Support\Str::uuid()]))->assertSessionHasErrorsIn('movement', ['category_id']);

        $this->assertSame(0, FinancialMovement::count());
    }

    public function test_cash_movements_require_an_open_cash_session(): void
    {
        $cat = $this->category('Insumos', 'egreso');

        $this->store($this->payload($cat, ['method' => 'efectivo']))->assertSessionHasErrorsIn('movement', ['method']);
        $this->assertSame(0, FinancialMovement::count());
    }

    public function test_cash_movements_attach_to_the_open_session_and_move_expected_cash(): void
    {
        $session = $this->openCash('1000.00');
        $income = $this->category('Otros ingresos', 'ingreso');
        $expense = $this->category('Insumos', 'egreso');

        $this->store($this->payload($income, ['method' => 'efectivo', 'amount' => '500.00']))->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $session->fresh()->expectedCash());

        $this->store($this->payload($expense, ['method' => 'efectivo', 'amount' => '300.00']))->assertSessionHasNoErrors();
        $this->assertSame('1200.00', $session->fresh()->expectedCash());

        $this->assertSame(2, FinancialMovement::where('cash_session_id', $session->id)->count());

        // Los movimientos que no son en efectivo no tocan la caja.
        $this->store($this->payload($expense, ['method' => 'tarjeta', 'amount' => '999.00']))->assertSessionHasNoErrors();
        $this->assertSame('1200.00', $session->fresh()->expectedCash());
        $this->assertNull(FinancialMovement::where('amount', '999.00')->first()->cash_session_id);
    }

    public function test_cash_expense_cannot_exceed_available_cash(): void
    {
        $this->openCash('100.00');
        $expense = $this->category('Insumos', 'egreso');

        $this->store($this->payload($expense, ['method' => 'efectivo', 'amount' => '100.01']))
            ->assertSessionHasErrorsIn('movement', ['amount']);
        $this->store($this->payload($expense, ['method' => 'efectivo', 'amount' => '100.00']))->assertSessionHasNoErrors();
    }

    public function test_receipt_is_optional_private_and_validated(): void
    {
        $cat = $this->category();

        $this->store($this->payload($cat, ['receipt' => UploadedFile::fake()->image('ticket.png')]))->assertSessionHasNoErrors();
        $movement = FinancialMovement::firstOrFail();
        $this->assertNotNull($movement->receipt_storage_key);
        Storage::disk('local')->assertExists($movement->receipt_storage_key);

        $this->get(route('finanzas.movimientos.receipt', $movement))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->store($this->payload($cat, ['receipt' => UploadedFile::fake()->create('virus.exe', 10)]))
            ->assertSessionHasErrorsIn('movement', ['receipt']);
        $this->store($this->payload($cat, ['receipt' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')]))
            ->assertSessionHasErrorsIn('movement', ['receipt']);
        $this->assertSame(1, FinancialMovement::count());
    }

    public function test_receipt_download_404_when_there_is_none(): void
    {
        $movement = $this->makeMovement($this->category());

        $this->get(route('finanzas.movimientos.receipt', $movement))->assertNotFound();
    }

    public function test_duplicate_submission_key_is_rejected(): void
    {
        $cat = $this->category();
        $payload = $this->payload($cat);

        $this->store($payload)->assertSessionHasNoErrors();
        $this->store($payload)->assertSessionHasErrorsIn('movement', ['concept']);

        $this->assertSame(1, FinancialMovement::count());
    }

    public function test_reversal_creates_the_opposite_movement_and_cancels_out_of_reports(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $original = $this->makeMovement($cat, ['amount' => '1240.00', 'concept' => 'Compra duplicada']);

        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Captura duplicada'])
            ->assertSessionHas('success');

        $reversal = FinancialMovement::where('reversed_of_id', $original->id)->firstOrFail();
        $this->assertSame('ingreso', $reversal->direction->value);
        $this->assertSame('1240.00', (string) $reversal->amount);
        $this->assertSame('Reversión de egreso', $reversal->category->name);
        $this->assertSame('ingreso', $reversal->category->direction->value);
        $this->assertStringContainsString('Captura duplicada', $reversal->concept);

        // El original no se modifica ni se borra.
        $this->assertDatabaseHas('financial_movements', ['id' => $original->id, 'amount' => '1240.00', 'direction' => 'egreso']);

        // El par no cuenta en los KPI.
        $kpis = $this->get(route('finanzas.index'))->viewData('kpis');
        $this->assertSame('0.00', $kpis['income']);
        $this->assertSame('0.00', $kpis['expense']);
    }

    public function test_a_movement_cannot_be_reversed_twice_nor_a_reversal_reversed(): void
    {
        $original = $this->makeMovement($this->category());

        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Error de captura'])->assertSessionHasNoErrors();
        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Otra vez'])->assertSessionHasErrorsIn('reverse', ['reason']);

        $reversal = FinancialMovement::where('reversed_of_id', $original->id)->first();
        $this->post(route('finanzas.movimientos.reverse', $reversal), ['reason' => 'Revertir reversion'])->assertSessionHasErrorsIn('reverse', ['reason']);

        $this->assertSame(2, FinancialMovement::count());
    }

    public function test_reversal_requires_a_reason(): void
    {
        $original = $this->makeMovement($this->category());

        $this->post(route('finanzas.movimientos.reverse', $original), [])->assertSessionHasErrorsIn('reverse', ['reason']);
        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'abc'])->assertSessionHasErrorsIn('reverse', ['reason']);
        $this->assertSame(1, FinancialMovement::count());
    }

    public function test_payment_movements_are_read_only(): void
    {
        $movement = $this->makePaymentMovement($this->category('Tratamientos', 'ingreso'));

        $this->post(route('finanzas.movimientos.reverse', $movement), ['reason' => 'Intento de reversion'])
            ->assertSessionHasErrorsIn('reverse', ['reason']);

        $this->assertSame(1, FinancialMovement::count());
        $this->assertFalse($movement->fresh()->isManual());
        // No hay ruta para editar ni borrar movimientos.
        $this->put('/finanzas/movimientos/'.$movement->id, [])->assertNotFound();
        $this->delete('/finanzas/movimientos/'.$movement->id)->assertNotFound();
    }

    public function test_cash_reversal_lands_in_the_open_session_and_restores_expected_cash(): void
    {
        $session = $this->openCash('1000.00');
        $income = $this->category('Otros ingresos', 'ingreso');
        $this->post(route('finanzas.movimientos.store'), $this->payload($income, ['method' => 'efectivo', 'amount' => '400.00']));
        $original = FinancialMovement::firstOrFail();
        $this->assertSame('1400.00', $session->fresh()->expectedCash());

        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Cobro cancelado'])->assertSessionHasNoErrors();

        $this->assertSame('1000.00', $session->fresh()->expectedCash());
        $this->assertSame($session->id, FinancialMovement::where('reversed_of_id', $original->id)->value('cash_session_id'));
    }

    public function test_cash_reversal_needs_an_open_session(): void
    {
        $session = $this->openCash('1000.00');
        $income = $this->category('Otros ingresos', 'ingreso');
        $this->post(route('finanzas.movimientos.store'), $this->payload($income, ['method' => 'efectivo', 'amount' => '400.00']));
        $original = FinancialMovement::firstOrFail();
        $this->post(route('finanzas.turnos.close', $session), ['counted_cash' => '1400.00'])->assertSessionHasNoErrors();

        $this->post(route('finanzas.movimientos.reverse', $original), ['reason' => 'Cobro cancelado'])
            ->assertSessionHasErrorsIn('reverse', ['method']);
        $this->assertSame(1, FinancialMovement::count());
    }

    public function test_reversal_categories_are_reused_and_reserved(): void
    {
        $service = app(MovementService::class);

        $a = $service->reversalCategory(\App\Enums\MovementDirection::Egreso);
        $b = $service->reversalCategory(\App\Enums\MovementDirection::Egreso);

        $this->assertTrue($a->is($b));
        $this->assertTrue(MovementService::isSystemCategoryName('reversión de egreso'));
    }

    public function test_database_rejects_non_positive_amounts_even_bypassing_the_form(): void
    {
        $cat = $this->category();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeMovement($cat, ['amount' => '0.00']);
    }
}

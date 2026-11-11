<?php

namespace Tests\Feature\Finanzas;

use Illuminate\Support\Facades\Auth;

class FinanzasAccessTest extends FinanzasTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        Auth::logout();

        foreach (['finanzas.index', 'finanzas.cajas.index', 'finanzas.categorias.index', 'finanzas.export'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_roles_without_finanzas_permission_get_403(): void
    {
        foreach (['actingAsReceptionist', 'actingAsDoctor'] as $actor) {
            $this->$actor();
            $this->get(route('finanzas.index'))->assertForbidden();
            $this->get(route('finanzas.cajas.index'))->assertForbidden();
            $this->get(route('finanzas.categorias.index'))->assertForbidden();
            $this->get(route('finanzas.export'))->assertForbidden();
            $this->post(route('finanzas.turnos.open'), [])->assertForbidden();
        }
    }

    public function test_admin_can_open_every_finance_screen(): void
    {
        $this->category('Tratamientos', 'ingreso');
        $session = $this->openCash();

        $this->get(route('finanzas.index'))->assertOk()
            ->assertSee('Finanzas')->assertSee('Nueva transacción')->assertSee('Corte de caja')
            ->assertSee('Gastos por categoría')->assertSee('Ingresos vs egresos');
        $this->get(route('finanzas.cajas.index'))->assertOk()->assertSee('Historial de turnos');
        $this->get(route('finanzas.cajas.show', $session))->assertOk()->assertSee('Movimientos del turno');
        $this->get(route('finanzas.categorias.index'))->assertOk()->assertSee('Nueva categoría');
    }

    public function test_index_creates_a_default_register_when_none_exists(): void
    {
        $this->assertDatabaseCount('cash_registers', 0);

        $this->get(route('finanzas.index'))->assertOk();

        $this->assertDatabaseHas('cash_registers', ['name' => 'Caja principal', 'branch_id' => $this->mainBranchId()]);
    }

    public function test_without_open_cash_the_header_offers_to_open_one(): void
    {
        $this->get(route('finanzas.index'))->assertOk()->assertSee('Abrir caja')->assertSee('sin turno abierto');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->from(route('finanzas.index'))
            ->get(route('finanzas.index', ['from' => '2026-06-30', 'to' => '2026-06-01']))
            ->assertRedirect(route('finanzas.index'))
            ->assertSessionHasErrors('to');

        $this->get(route('finanzas.index', ['method' => 'bitcoin']))->assertSessionHasErrors('method');
        $this->get(route('finanzas.index', ['category_id' => 'no-es-uuid']))->assertSessionHasErrors('category_id');
        $this->get(route('finanzas.index', ['from' => 'ayer']))->assertSessionHasErrors('from');
    }
}

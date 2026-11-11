<?php

namespace Tests\Feature\Finanzas;

use App\Enums\MovementDirection;
use App\Models\CashRegister;
use App\Models\FinancialCategory;
use App\Services\Finanzas\MovementService;

class CategoryAndRegisterTest extends FinanzasTestCase
{
    public function test_it_creates_categories(): void
    {
        $this->post(route('finanzas.categorias.store'), ['name' => '  Mantenimiento   de equipo ', 'direction' => 'egreso'])
            ->assertRedirect(route('finanzas.categorias.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('financial_categories', ['name' => 'Mantenimiento de equipo', 'direction' => 'egreso', 'status' => 'active']);
    }

    public function test_category_name_is_unique_per_direction_only(): void
    {
        $this->category('Donativos', 'ingreso');

        $this->post(route('finanzas.categorias.store'), ['name' => 'donativos', 'direction' => 'ingreso'])
            ->assertSessionHasErrorsIn('category', ['name']);
        $this->post(route('finanzas.categorias.store'), ['name' => 'Donativos', 'direction' => 'egreso'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, FinancialCategory::where('name', 'Donativos')->count());
    }

    public function test_category_validation(): void
    {
        $url = route('finanzas.categorias.store');

        $this->post($url, [])->assertSessionHasErrorsIn('category', ['name', 'direction']);
        $this->post($url, ['name' => 'A', 'direction' => 'egreso'])->assertSessionHasErrorsIn('category', ['name']);
        $this->post($url, ['name' => str_repeat('x', 101), 'direction' => 'egreso'])->assertSessionHasErrorsIn('category', ['name']);
        $this->post($url, ['name' => 'Valida', 'direction' => 'otra'])->assertSessionHasErrorsIn('category', ['direction']);
        $this->post($url, ['name' => 'Reversión de egreso', 'direction' => 'ingreso'])->assertSessionHasErrorsIn('category', ['name']);
        $this->assertSame(0, FinancialCategory::count());
    }

    public function test_category_can_be_renamed_but_not_retyped(): void
    {
        $cat = $this->category('Papeleria', 'egreso');
        $other = $this->category('Renta', 'egreso');

        $this->put(route('finanzas.categorias.update', $cat), ['name' => 'Papelería y oficina'])->assertSessionHasNoErrors();
        $this->assertSame('Papelería y oficina', $cat->fresh()->name);

        $this->put(route('finanzas.categorias.update', $cat), ['name' => 'Renta'])->assertSessionHasErrorsIn('category', ['name']);
        $this->put(route('finanzas.categorias.update', $cat), ['name' => 'Otra', 'direction' => 'ingreso'])->assertSessionHasErrorsIn('category', ['direction']);
        $this->assertSame('egreso', $cat->fresh()->direction->value);

        // Conservar el mismo nombre al editar es valido.
        $this->put(route('finanzas.categorias.update', $other), ['name' => 'Renta'])->assertSessionHasNoErrors();
    }

    public function test_categories_are_toggled_not_deleted_and_history_is_kept(): void
    {
        $cat = $this->category('Insumos', 'egreso');
        $this->makeMovement($cat);

        $this->patch(route('finanzas.categorias.toggle', $cat))->assertSessionHas('success');
        $this->assertSame('inactive', $cat->fresh()->status->value);
        $this->assertSame(1, $cat->movements()->count());

        $this->patch(route('finanzas.categorias.toggle', $cat));
        $this->assertSame('active', $cat->fresh()->status->value);

        $this->delete('/finanzas/categorias/'.$cat->id)->assertStatus(405);
    }

    public function test_inactive_categories_disappear_from_new_transactions(): void
    {
        $cat = $this->category('Antigua', 'egreso');
        $this->patch(route('finanzas.categorias.toggle', $cat));

        $this->get(route('finanzas.index'))->assertOk()->assertDontSee('data-direction="egreso">Antigua');
    }

    public function test_system_reversal_categories_are_protected(): void
    {
        $system = app(MovementService::class)->reversalCategory(MovementDirection::Ingreso);

        $this->put(route('finanzas.categorias.update', $system), ['name' => 'Renombrada'])->assertSessionHasErrorsIn('category', ['name']);
        $this->patch(route('finanzas.categorias.toggle', $system))->assertSessionHas('error');
        $this->assertSame('active', $system->fresh()->status->value);
        $this->assertSame('Reversión de ingreso', $system->fresh()->name);
    }

    public function test_it_creates_registers_with_unique_names_per_branch(): void
    {
        $branch = $this->mainBranchId();

        $this->post(route('finanzas.cajas.store'), ['name' => 'Recepción', 'branch_id' => $branch])->assertSessionHasNoErrors();
        $this->post(route('finanzas.cajas.store'), ['name' => 'Recepción', 'branch_id' => $branch])->assertSessionHasErrorsIn('register', ['name']);
        $this->post(route('finanzas.cajas.store'), ['name' => '', 'branch_id' => $branch])->assertSessionHasErrorsIn('register', ['name']);
        $this->post(route('finanzas.cajas.store'), ['name' => 'Otra', 'branch_id' => (string) \Illuminate\Support\Str::uuid()])->assertSessionHasErrorsIn('register', ['branch_id']);

        $this->assertSame(1, CashRegister::count());
    }

    public function test_register_update_and_deactivation_rules(): void
    {
        $register = $this->cashRegister('Caja 1');

        $this->put(route('finanzas.cajas.update', $register), ['name' => 'Caja recepción', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('Caja recepción', $register->fresh()->name);

        $this->put(route('finanzas.cajas.update', $register), ['name' => 'X', 'status' => 'bloqueada'])->assertSessionHasErrorsIn('register', ['status']);

        // Con turno abierto no se puede desactivar.
        $this->post(route('finanzas.turnos.open'), ['cash_register_id' => $register->id, 'opening_amount' => '0']);
        $this->put(route('finanzas.cajas.update', $register), ['name' => 'Caja recepción', 'status' => 'inactive'])
            ->assertSessionHasErrorsIn('register', ['status']);
        $this->assertSame('active', $register->fresh()->status->value);
    }
}

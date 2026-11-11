<?php

namespace Tests\Feature\Inventario;

use App\Models\Supplier;

/** Resumen, filtros, transacciones, kardex, exportacion CSV y proveedores. */
class InventarioListadoTest extends InventarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_summary_flags_low_and_out_of_stock_and_computes_kpis(): void
    {
        $this->makeItem(['sku' => 'OK-1', 'name' => 'Algodones', 'initial_stock' => '45', 'min_quantity' => '20', 'unit_cost' => '85']);
        $low = $this->makeItem(['sku' => 'LOW-1', 'name' => 'Lidocaina', 'initial_stock' => '8', 'min_quantity' => '20', 'unit_cost' => '18.50']);
        $out = $this->makeItem(['sku' => 'OUT-1', 'name' => 'Fresas', 'initial_stock' => '3', 'min_quantity' => '5', 'unit_cost' => '240']);
        $this->salida($out, '3');

        $res = $this->get(route('inventario.index'))->assertOk();
        $res->assertSee('2 insumos por debajo del stock mínimo');
        $res->assertSee('inv-row--out', false)->assertSee('inv-row--low', false);

        // 45*85 + 8*18.5 + 0*240 = 3,825 + 148 = $3,973.00
        $res->assertSee('$3,973.00');

        $names = $this->get(route('inventario.index', ['filter' => 'bajo']))->viewData('items')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Lidocaina', 'Fresas'], $names);

        $names = $this->get(route('inventario.index', ['filter' => 'agotado']))->viewData('items')->pluck('name')->all();
        $this->assertSame(['Fresas'], $names);
        $this->assertTrue((bool) $low->refresh()->exists);
    }

    public function test_search_and_category_filters(): void
    {
        $this->makeItem(['sku' => 'A-1', 'name' => 'Anestesia', 'category' => 'Anestésicos']);
        $this->makeItem(['sku' => 'G-1', 'name' => 'Guantes', 'category' => 'Protección']);

        $by = fn (array $q) => $this->get(route('inventario.index', $q))->viewData('items')->pluck('sku')->all();

        $this->assertSame(['G-1'], $by(['q' => 'guan']));
        $this->assertSame(['A-1'], $by(['q' => 'a-1']));
        $this->assertSame(['A-1'], $by(['category' => 'Anestésicos']));
        $this->assertSame([], $by(['q' => '100%_']));
        // Valores invalidos de filtros se ignoran sin error.
        $this->assertCount(2, $by(['filter' => 'hack', 'status' => 'zzz', 'vista' => 'x']));
    }

    public function test_inactive_items_are_hidden_by_default(): void
    {
        $item = $this->makeItem();
        $this->patch(route('inventario.items.toggle', $item));

        $this->assertCount(0, $this->get(route('inventario.index'))->viewData('items'));
        $this->assertCount(1, $this->get(route('inventario.index', ['status' => 'inactive']))->viewData('items'));
        $this->assertCount(1, $this->get(route('inventario.index', ['status' => 'all']))->viewData('items'));
    }

    public function test_expiring_and_expired_lots_are_reported(): void
    {
        $item = $this->makeItem();
        $this->entrada($item, '2', '10', ['lot_code' => 'SOON', 'expires_on' => now()->addDays(10)->toDateString()]);
        $this->entrada($item, '2', '10', ['lot_code' => 'LATER', 'expires_on' => now()->addDays(200)->toDateString()]);
        \Illuminate\Support\Facades\DB::table('inventory_lots')->where('lot_code', 'LATER')->update(['expires_on' => now()->subDays(3)->toDateString()]);

        $kpis = $this->get(route('inventario.index'))->viewData('kpis');
        $this->assertSame(1, $kpis['expiring']);
        $this->assertSame(1, $kpis['expired']);

        $this->assertCount(1, $this->get(route('inventario.index', ['filter' => 'caducan']))->viewData('items'));
    }

    public function test_transactions_view_lists_and_filters_movements(): void
    {
        $item = $this->makeItem();
        $this->entrada($item, '5');
        $this->salida($item, '2');

        $res = $this->get(route('inventario.index', ['vista' => 'transacciones']))->assertOk();
        $this->assertCount(3, $res->viewData('movements'));
        $res->assertSee('Revertir');

        $types = $this->get(route('inventario.index', ['vista' => 'transacciones', 'type' => 'salida']))->viewData('movements')->pluck('movement_type')->map->value->all();
        $this->assertSame(['salida'], $types);

        $this->assertCount(0, $this->get(route('inventario.index', ['vista' => 'transacciones', 'from' => now()->addDay()->toDateString()]))->viewData('movements'));
        $this->assertCount(3, $this->get(route('inventario.index', ['vista' => 'transacciones', 'q' => 'GNT']))->viewData('movements'));
    }

    public function test_kardex_shows_running_balance(): void
    {
        $item = $this->makeItem(['initial_stock' => '10']);
        $this->entrada($item, '5');
        $this->salida($item, '3');

        $kardex = $this->get(route('inventario.items.show', $item))->assertOk()->viewData('kardex');
        $balances = $kardex->getCollection()->map(fn ($r) => (float) $r->balance)->sort()->values()->all();

        $this->assertSame([10.0, 12.0, 15.0], $balances);
        $this->assertEquals(12, $this->onHand($item));
    }

    public function test_csv_export_has_bom_headers_and_neutralizes_formulas(): void
    {
        $this->makeItem(['sku' => 'CSV-1', 'name' => '=HYPERLINK("http://x")']);

        $csv = $this->get(route('inventario.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Insumo,SKU,Categoría', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        $csv = $this->get(route('inventario.export', ['vista' => 'transacciones']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Fecha,Insumo,SKU', $csv);
        $this->assertStringContainsString('Inventario inicial', $csv);
    }

    // ------------------------------- Proveedores -------------------------------

    public function test_supplier_crud_and_validation(): void
    {
        $this->post(route('inventario.proveedores.store'), ['name' => 'MediSupply', 'contact_phone' => '55 1234 5678', 'contact_email' => 'VENTAS@Medisupply.mx'])
            ->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('name', 'MediSupply')->firstOrFail();
        $this->assertSame('5512345678', $supplier->contact_phone);
        $this->assertSame('ventas@medisupply.mx', $supplier->contact_email);

        $this->post(route('inventario.proveedores.store'), ['name' => 'medisupply'])->assertSessionHasErrors('name', null, 'proveedor');
        $this->post(route('inventario.proveedores.store'), ['name' => 'X1', 'contact_phone' => '123'])->assertSessionHasErrors('contact_phone', null, 'proveedor');
        $this->post(route('inventario.proveedores.store'), ['name' => 'X2', 'contact_email' => 'no-es-correo'])->assertSessionHasErrors('contact_email', null, 'proveedor');
        $this->post(route('inventario.proveedores.store'), ['name' => ''])->assertSessionHasErrors('name', null, 'proveedor');

        $this->put(route('inventario.proveedores.update', $supplier), ['name' => 'MediSupply MX'])->assertSessionHasNoErrors();
        $this->assertSame('MediSupply MX', $supplier->refresh()->name);
        // Puede conservar su propio nombre.
        $this->put(route('inventario.proveedores.update', $supplier), ['name' => 'MediSupply MX'])->assertSessionHasNoErrors();

        $this->patch(route('inventario.proveedores.toggle', $supplier));
        $this->assertSame('inactive', $supplier->refresh()->status->value);
        $this->assertCount(0, $this->get(route('inventario.proveedores.index'))->viewData('suppliers'));
        $this->assertCount(1, $this->get(route('inventario.proveedores.index', ['status' => 'inactive']))->viewData('suppliers'));
    }

    public function test_supplier_with_items_cannot_be_deleted_but_empty_one_can(): void
    {
        $used = $this->makeSupplier('Con insumos');
        $this->makeItem(['supplier_id' => $used->id]);
        $empty = $this->makeSupplier('Sin insumos');

        $this->delete(route('inventario.proveedores.destroy', $used))->assertSessionHas('error');
        $this->assertDatabaseHas('suppliers', ['id' => $used->id]);

        $this->delete(route('inventario.proveedores.destroy', $empty))->assertSessionHas('success');
        $this->assertDatabaseMissing('suppliers', ['id' => $empty->id]);
    }
}

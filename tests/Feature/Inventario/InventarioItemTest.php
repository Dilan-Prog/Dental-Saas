<?php

namespace Tests\Feature\Inventario;

use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/** Alta, edicion y validaciones de insumos. */
class InventarioItemTest extends InventarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_creating_an_item_with_initial_stock_creates_movement_and_stock(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem(['supplier_id' => $supplier->id, 'sku' => 'gnt-nit-m']);

        $this->assertSame('GNT-NIT-M', $item->sku, 'El SKU se normaliza a mayusculas');
        $this->assertSame($supplier->id, $item->supplier_id);

        $stock = $this->stock($item);
        $this->assertEquals(10, $stock->quantity_on_hand);
        $this->assertEquals(4, $stock->min_quantity);
        $this->assertEquals(100, $stock->avg_unit_cost);
        $this->assertSame('Gaveta B-1', $stock->location);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $item->id,
            'movement_type' => 'inventario_inicial',
            'quantity' => '10.0000',
        ]);
    }

    public function test_creating_an_item_without_initial_stock_only_configures_the_branch(): void
    {
        $item = $this->makeItem(['initial_stock' => '', 'unit_cost' => '']);

        $this->assertEquals(0, $this->stock($item)->quantity_on_hand);
        $this->assertSame(0, DB::table('inventory_movements')->where('item_id', $item->id)->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->post(route('inventario.items.store'), [])
            ->assertSessionHasErrors(['name', 'sku', 'unit_of_measure', 'branch_id'], null, 'insumo');
    }

    public function test_sku_must_be_unique_and_well_formed(): void
    {
        $this->makeItem();

        $this->post(route('inventario.items.store'), ['name' => 'Otro', 'sku' => 'gnt-nit-m', 'unit_of_measure' => 'Caja', 'branch_id' => $this->mainBranchId()])
            ->assertSessionHasErrors('sku', null, 'insumo');

        $this->post(route('inventario.items.store'), ['name' => 'Otro', 'sku' => 'BAD SKU!', 'unit_of_measure' => 'Caja', 'branch_id' => $this->mainBranchId()])
            ->assertSessionHasErrors('sku', null, 'insumo');
    }

    public function test_quantities_and_costs_are_validated(): void
    {
        $base = ['name' => 'X', 'sku' => 'X-1', 'unit_of_measure' => 'Pieza', 'branch_id' => $this->mainBranchId()];

        $this->post(route('inventario.items.store'), $base + ['initial_stock' => '-1'])->assertSessionHasErrors('initial_stock', null, 'insumo');
        $this->post(route('inventario.items.store'), $base + ['min_quantity' => '-3'])->assertSessionHasErrors('min_quantity', null, 'insumo');
        $this->post(route('inventario.items.store'), $base + ['min_quantity' => '1.23456'])->assertSessionHasErrors('min_quantity', null, 'insumo');
        $this->post(route('inventario.items.store'), $base + ['initial_stock' => 'abc'])->assertSessionHasErrors('initial_stock', null, 'insumo');
        $this->post(route('inventario.items.store'), $base + ['unit_cost' => '-5'])->assertSessionHasErrors('unit_cost', null, 'insumo');
        // Con stock inicial el costo es obligatorio.
        $this->post(route('inventario.items.store'), $base + ['initial_stock' => '5'])->assertSessionHasErrors('unit_cost', null, 'insumo');
        $this->assertSame(0, InventoryItem::query()->count());
    }

    public function test_supplier_and_branch_must_exist_in_the_organization(): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';

        $this->post(route('inventario.items.store'), [
            'name' => 'X', 'sku' => 'X-2', 'unit_of_measure' => 'Pieza', 'branch_id' => $uuid, 'supplier_id' => $uuid,
        ])->assertSessionHasErrors(['branch_id', 'supplier_id'], null, 'insumo');
    }

    public function test_money_style_numbers_are_normalized(): void
    {
        $item = $this->makeItem(['sku' => 'MON-1', 'initial_stock' => '1,250', 'unit_cost' => '$18.50']);

        $this->assertEquals(1250, $this->stock($item)->quantity_on_hand);
        $this->assertEquals(18.5, $this->stock($item)->avg_unit_cost);
    }

    public function test_update_changes_data_but_never_the_stock(): void
    {
        $item = $this->makeItem();
        $other = $this->makeItem(['sku' => 'OTRO-1', 'name' => 'Otro']);

        $this->put(route('inventario.items.update', $item), [
            'name' => 'Guantes nitrilo (L)', 'sku' => 'gnt-nit-l', 'category' => 'Protección', 'unit_of_measure' => 'Caja',
        ])->assertRedirect(route('inventario.items.show', $item));

        $item->refresh();
        $this->assertSame('Guantes nitrilo (L)', $item->name);
        $this->assertSame('GNT-NIT-L', $item->sku);
        $this->assertEquals(10, $this->stock($item)->quantity_on_hand);

        // El SKU de otro insumo no se puede tomar; el propio si se puede conservar.
        $this->put(route('inventario.items.update', $item), ['name' => 'Guantes XL', 'sku' => $other->sku, 'unit_of_measure' => 'Caja'])
            ->assertSessionHasErrors('sku', null, 'insumo_edit');
        $this->put(route('inventario.items.update', $item), ['name' => 'Guantes XL', 'sku' => 'GNT-NIT-L', 'unit_of_measure' => 'Caja'])
            ->assertSessionHasNoErrors();
    }

    public function test_stock_settings_update_min_and_location_without_touching_quantity(): void
    {
        $item = $this->makeItem();

        $this->put(route('inventario.items.stock-settings', $item), [
            'branch_id' => $this->mainBranchId(), 'min_quantity' => '7.5', 'location' => 'Refrigerador',
        ])->assertSessionHasNoErrors();

        $stock = $this->stock($item);
        $this->assertEquals(7.5, $stock->min_quantity);
        $this->assertSame('Refrigerador', $stock->location);
        $this->assertEquals(10, $stock->quantity_on_hand);

        $this->put(route('inventario.items.stock-settings', $item), ['branch_id' => $this->mainBranchId(), 'min_quantity' => '-1'])
            ->assertSessionHasErrors('min_quantity', null, 'stock');
    }

    public function test_toggle_deactivates_and_blocks_new_movements(): void
    {
        $item = $this->makeItem();

        $this->patch(route('inventario.items.toggle', $item))->assertSessionHas('success');
        $this->assertSame('inactive', $item->refresh()->status->value);

        $this->entrada($item, '5')->assertSessionHasErrors('item_id', null, 'entrada');
        $this->assertEquals(10, $this->stock($item)->quantity_on_hand);

        $this->patch(route('inventario.items.toggle', $item));
        $this->assertSame('active', $item->refresh()->status->value);
        $this->entrada($item, '5')->assertSessionHasNoErrors();
    }
}

<?php

namespace Tests\Feature\Inventario;

use App\Models\InventoryItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ayudas comunes de las pruebas del modulo Inventario. */
abstract class InventarioTestCase extends TestCase
{
    /** Crea un insumo por la ruta real (como lo haria el formulario) y lo devuelve. */
    protected function makeItem(array $overrides = []): InventoryItem
    {
        $data = array_merge([
            'name' => 'Guantes nitrilo (M)',
            'sku' => 'GNT-NIT-M',
            'category' => 'Protección',
            'unit_of_measure' => 'Caja',
            'branch_id' => $this->mainBranchId(),
            'initial_stock' => '10',
            'min_quantity' => '4',
            'unit_cost' => '100',
            'location' => 'Gaveta B-1',
        ], $overrides);

        $this->post(route('inventario.items.store'), $data)->assertSessionHasNoErrors();

        return InventoryItem::query()->where('sku', mb_strtoupper($data['sku']))->firstOrFail();
    }

    protected function makeSupplier(string $name = 'MediSupply'): Supplier
    {
        return Supplier::create(['name' => $name, 'status' => 'active']);
    }

    protected function stock(InventoryItem $item, ?string $branchId = null): ?object
    {
        return DB::table('inventory_stock')
            ->where('item_id', $item->id)
            ->where('branch_id', $branchId ?? $this->mainBranchId())
            ->first();
    }

    protected function onHand(InventoryItem $item): string
    {
        return (string) ($this->stock($item)->quantity_on_hand ?? '0');
    }

    protected function entrada(InventoryItem $item, string $qty, string $cost = '100', array $extra = [])
    {
        return $this->post(route('inventario.movimientos.entrada'), array_merge([
            'item_id' => $item->id,
            'branch_id' => $this->mainBranchId(),
            'quantity' => $qty,
            'unit_cost' => $cost,
        ], $extra));
    }

    protected function salida(InventoryItem $item, string $qty, array $extra = [])
    {
        return $this->post(route('inventario.movimientos.salida'), array_merge([
            'item_id' => $item->id,
            'branch_id' => $this->mainBranchId(),
            'quantity' => $qty,
            'reason' => 'Uso en consulta',
        ], $extra));
    }
}

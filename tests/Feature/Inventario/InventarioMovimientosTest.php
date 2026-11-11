<?php

namespace Tests\Feature\Inventario;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

/** Entradas, salidas, ajustes, inventario inicial y reversiones (stock coherente con los triggers). */
class InventarioMovimientosTest extends InventarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    public function test_entrada_adds_stock_and_updates_weighted_average_cost(): void
    {
        $item = $this->makeItem(); // 10 a $100

        $this->entrada($item, '10', '200')->assertSessionHasNoErrors()->assertSessionHas('success');

        $stock = $this->stock($item);
        $this->assertEquals(20, $stock->quantity_on_hand);
        $this->assertEquals(150, $stock->avg_unit_cost); // (10*100 + 10*200) / 20
    }

    public function test_entrada_validation(): void
    {
        $item = $this->makeItem();

        $this->entrada($item, '0')->assertSessionHasErrors('quantity', null, 'entrada');
        $this->entrada($item, '-3')->assertSessionHasErrors('quantity', null, 'entrada');
        $this->entrada($item, '1.00001')->assertSessionHasErrors('quantity', null, 'entrada');
        $this->entrada($item, 'abc')->assertSessionHasErrors('quantity', null, 'entrada');
        $this->entrada($item, '5', '-1')->assertSessionHasErrors('unit_cost', null, 'entrada');
        $this->entrada($item, '5', '')->assertSessionHasErrors('unit_cost', null, 'entrada');
        $this->entrada($item, '5', '10', ['expires_on' => now()->subDay()->toDateString(), 'lot_code' => 'L1'])->assertSessionHasErrors('expires_on', null, 'entrada');
        $this->entrada($item, '5', '10', ['expires_on' => now()->addDays(10)->toDateString()])->assertSessionHasErrors('lot_code', null, 'entrada');
        $this->entrada($item, '5', '10', ['lot_code' => 'BAD*LOT'])->assertSessionHasErrors('lot_code', null, 'entrada');
        $this->entrada($item, '5', '10', ['supplier_id' => '33333333-3333-4333-8333-333333333333'])->assertSessionHasErrors('supplier_id', null, 'entrada');
        $this->entrada($item, '5', '10', ['encounter_id' => '33333333-3333-4333-8333-333333333333'])->assertSessionHasErrors('encounter_id', null, 'entrada');

        $this->assertEquals(10, $this->onHand($item));
    }

    public function test_entrada_with_lot_creates_and_accumulates_the_lot(): void
    {
        $item = $this->makeItem();
        $exp = now()->addDays(60)->toDateString();

        $this->entrada($item, '5', '100', ['lot_code' => 'l2606-a', 'expires_on' => $exp])->assertSessionHasNoErrors();
        $this->entrada($item, '3', '100', ['lot_code' => 'L2606-A'])->assertSessionHasNoErrors();

        $lot = InventoryLot::query()->where('item_id', $item->id)->firstOrFail();
        $this->assertSame('L2606-A', $lot->lot_code);
        $this->assertEquals(8, $lot->quantity_on_hand);
        $this->assertSame($exp, $lot->expires_on->toDateString());

        // Mismo lote con otra caducidad: se rechaza.
        $this->entrada($item, '1', '100', ['lot_code' => 'L2606-A', 'expires_on' => now()->addDays(90)->toDateString()])
            ->assertSessionHasErrors('expires_on', null, 'entrada');
        $this->assertEquals(8, $lot->refresh()->quantity_on_hand);
    }

    public function test_entrada_assigns_the_supplier_when_the_item_has_none(): void
    {
        $item = $this->makeItem();
        $supplier = $this->makeSupplier('Dental Pro');

        $this->entrada($item, '2', '100', ['supplier_id' => $supplier->id])->assertSessionHasNoErrors();

        $this->assertSame($supplier->id, $item->refresh()->supplier_id);
        $this->assertDatabaseHas('inventory_movements', ['item_id' => $item->id, 'reason' => 'Compra a Dental Pro']);
    }

    public function test_salida_subtracts_stock_and_requires_a_reason(): void
    {
        $item = $this->makeItem();

        $this->salida($item, '4')->assertSessionHasNoErrors();
        $this->assertEquals(6, $this->onHand($item));
        $this->assertDatabaseHas('inventory_movements', ['item_id' => $item->id, 'movement_type' => 'salida', 'quantity' => '-4.0000']);

        $this->salida($item, '1', ['reason' => ''])->assertSessionHasErrors('reason', null, 'salida');
        $this->salida($item, '0')->assertSessionHasErrors('quantity', null, 'salida');
        $this->assertEquals(6, $this->onHand($item));
    }

    public function test_salida_can_never_leave_negative_stock(): void
    {
        $item = $this->makeItem();

        $this->salida($item, '10.0001')->assertSessionHasErrors('quantity', null, 'salida');
        $this->assertEquals(10, $this->onHand($item));

        $this->salida($item, '10')->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->onHand($item));

        $this->salida($item, '1')->assertSessionHasErrors('quantity', null, 'salida');
        $this->assertEquals(0, $this->onHand($item));
    }

    public function test_salida_from_a_lot_respects_lot_quantity(): void
    {
        $item = $this->makeItem(['initial_stock' => '10']);
        $this->entrada($item, '5', '100', ['lot_code' => 'L1', 'expires_on' => now()->addDays(40)->toDateString()]);
        $lot = InventoryLot::query()->where('item_id', $item->id)->firstOrFail();

        $this->salida($item, '3', ['lot_id' => $lot->id])->assertSessionHasNoErrors();
        $this->assertEquals(2, $lot->refresh()->quantity_on_hand);
        $this->assertEquals(12, $this->onHand($item));

        // Hay stock total de sobra pero el lote solo tiene 2.
        $this->salida($item, '3', ['lot_id' => $lot->id])->assertSessionHasErrors('lot_id', null, 'salida');
        $this->assertEquals(2, $lot->refresh()->quantity_on_hand);
        $this->assertEquals(12, $this->onHand($item));
    }

    public function test_salida_can_be_linked_to_a_consultation(): void
    {
        $item = $this->makeItem();
        $encounterId = $this->createEncounter();

        $this->salida($item, '1', ['encounter_id' => $encounterId])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_movements', ['item_id' => $item->id, 'encounter_id' => $encounterId]);
    }

    public function test_ajuste_accepts_sign_requires_reason_and_rejects_zero(): void
    {
        $item = $this->makeItem();
        $post = fn (array $d) => $this->post(route('inventario.movimientos.ajuste'), $d + [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'reason' => 'Conteo físico',
        ]);

        $post(['quantity' => '-2'])->assertSessionHasNoErrors();
        $this->assertEquals(8, $this->onHand($item));

        $post(['quantity' => '3.5'])->assertSessionHasNoErrors();
        $this->assertEquals(11.5, $this->onHand($item));

        $post(['quantity' => '0'])->assertSessionHasErrors('quantity', null, 'ajuste');
        $post(['quantity' => '1', 'reason' => ''])->assertSessionHasErrors('reason', null, 'ajuste');
        $post(['quantity' => '-100'])->assertSessionHasErrors('quantity', null, 'ajuste');
        $this->assertEquals(11.5, $this->onHand($item));
    }

    public function test_initial_inventory_can_only_be_registered_once_per_branch(): void
    {
        $item = $this->makeItem(['initial_stock' => '', 'unit_cost' => '']);
        $payload = ['item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '12', 'unit_cost' => '50'];

        $this->post(route('inventario.movimientos.inicial'), $payload)->assertSessionHasNoErrors();
        $this->assertEquals(12, $this->onHand($item));

        $this->post(route('inventario.movimientos.inicial'), $payload)->assertSessionHasErrors('item_id', null, 'inicial');
        $this->assertEquals(12, $this->onHand($item));
        $this->assertSame(1, InventoryMovement::query()->where('item_id', $item->id)->where('movement_type', 'inventario_inicial')->count());
    }

    public function test_reversion_creates_the_opposite_movement_once(): void
    {
        $item = $this->makeItem();
        $this->entrada($item, '5');
        $entrada = InventoryMovement::query()->where('item_id', $item->id)->where('movement_type', 'entrada')->firstOrFail();

        $this->post(route('inventario.movimientos.reversion', $entrada), ['reason' => 'Captura duplicada'])->assertSessionHasNoErrors();
        $this->assertEquals(10, $this->onHand($item));
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $item->id, 'movement_type' => 'reversion', 'quantity' => '-5.0000', 'reverses_movement_id' => $entrada->id,
        ]);

        // No se revierte dos veces.
        $this->post(route('inventario.movimientos.reversion', $entrada), ['reason' => 'otra vez'])->assertSessionHasErrors('reason', null, 'reversion');
        $this->assertEquals(10, $this->onHand($item));

        // Una reversion no se revierte.
        $reversion = InventoryMovement::query()->where('reverses_movement_id', $entrada->id)->firstOrFail();
        $this->post(route('inventario.movimientos.reversion', $reversion), ['reason' => 'x y z'])->assertSessionHasErrors('reason', null, 'reversion');
    }

    public function test_reversion_of_a_salida_restores_stock_and_lot(): void
    {
        $item = $this->makeItem();
        $this->entrada($item, '5', '100', ['lot_code' => 'LX', 'expires_on' => now()->addDays(30)->toDateString()]);
        $lot = InventoryLot::query()->where('item_id', $item->id)->firstOrFail();
        $this->salida($item, '4', ['lot_id' => $lot->id]);
        $this->assertEquals(1, $lot->refresh()->quantity_on_hand);

        $salida = InventoryMovement::query()->where('item_id', $item->id)->where('movement_type', 'salida')->firstOrFail();
        $this->post(route('inventario.movimientos.reversion', $salida), ['reason' => 'Se capturó mal'])->assertSessionHasNoErrors();

        $this->assertEquals(15, $this->onHand($item));
        $this->assertEquals(5, $lot->refresh()->quantity_on_hand);
    }

    public function test_reversion_that_would_leave_negative_stock_is_rejected(): void
    {
        $item = $this->makeItem(['initial_stock' => '']);
        $this->entrada($item, '5');
        $this->salida($item, '5');
        $entrada = InventoryMovement::query()->where('item_id', $item->id)->where('movement_type', 'entrada')->firstOrFail();

        $this->post(route('inventario.movimientos.reversion', $entrada), ['reason' => 'Error de captura'])->assertSessionHasErrors('reason', null, 'reversion');
        $this->assertEquals(0, $this->onHand($item));
    }

    public function test_initial_inventory_cannot_be_reversed_and_reason_is_required(): void
    {
        $item = $this->makeItem();
        $initial = InventoryMovement::query()->where('item_id', $item->id)->where('movement_type', 'inventario_inicial')->firstOrFail();

        $this->post(route('inventario.movimientos.reversion', $initial), ['reason' => 'Quiero revertir'])->assertSessionHasErrors('reason', null, 'reversion');
        $this->post(route('inventario.movimientos.reversion', $initial), ['reason' => ''])->assertSessionHasErrors('reason', null, 'reversion');
        $this->assertEquals(10, $this->onHand($item));
    }

    public function test_movements_are_immutable_in_the_database(): void
    {
        $item = $this->makeItem();
        $movement = InventoryMovement::query()->where('item_id', $item->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_movements')->where('id', $movement->id)->update(['quantity' => 99]);
    }

    public function test_stock_total_equals_the_sum_of_signed_movements(): void
    {
        $item = $this->makeItem(['initial_stock' => '20']);
        $this->entrada($item, '7.5', '90');
        $this->salida($item, '3.25');
        $this->post(route('inventario.movimientos.ajuste'), ['item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '-1.25', 'reason' => 'Merma']);

        $sum = DB::table('inventory_movements')->where('item_id', $item->id)->sum('quantity');
        $this->assertEquals($sum, $this->onHand($item));
        $this->assertEquals(23, $this->onHand($item));
    }

    /** Crea una consulta clinica minima (paciente -> expediente -> consulta) para vincular salidas. */
    private function createEncounter(): string
    {
        $org = $this->organizationId();
        $patientId = (string) \Illuminate\Support\Str::uuid();
        $recordId = (string) \Illuminate\Support\Str::uuid();
        $encounterId = (string) \Illuminate\Support\Str::uuid();

        DB::table('patients')->insert(['id' => $patientId, 'organization_id' => $org, 'folio' => 'PAC-T' . random_int(1000, 9999), 'full_name' => 'Paciente de Prueba']);
        DB::table('medical_records')->insert(['id' => $recordId, 'organization_id' => $org, 'patient_id' => $patientId]);
        DB::table('clinical_encounters')->insert([
            'id' => $encounterId, 'organization_id' => $org, 'medical_record_id' => $recordId, 'branch_id' => $this->mainBranchId(),
            'doctor_member_id' => $this->memberId($this->userByEmail('doctor@dental.test')), 'occurred_at' => now(),
        ]);

        return $encounterId;
    }
}

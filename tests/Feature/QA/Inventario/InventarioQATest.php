<?php

namespace Tests\Feature\QA\Inventario;

use App\Models\InventoryMovement;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Inventario\InventarioTestCase;

/** QA4: validaciones y coherencia de Inventario. */
class InventarioQATest extends InventarioTestCase
{
    protected function setUp(): void
    {
        Organization::flushCurrent();
        parent::setUp();
        \Illuminate\Testing\TestResponse::macro('assertAnyBagErrors', function (?string $field = null) {
            $bags = session('errors')?->getBags() ?? [];
            $ok = false;
            foreach ($bags as $bag) {
                if ($field === null ? $bag->isNotEmpty() : $bag->has($field)) {
                    $ok = true;
                }
            }
            \PHPUnit\Framework\Assert::assertTrue($ok, 'Sin error'.($field ? " en {$field}" : '').' en ninguna bolsa; estado '.$this->getStatusCode());

            return $this;
        });
        Organization::flushCurrent();
        $this->actingAsAdmin();
    }

    protected function tearDown(): void
    {
        Organization::flushCurrent();
        parent::tearDown();
    }

    private function itemPayload(array $over = []): array
    {
        return $over + [
            'name' => 'Resina A2', 'sku' => 'RES-A2', 'category' => 'Restauración', 'unit_of_measure' => 'Jeringa',
            'branch_id' => $this->mainBranchId(), 'initial_stock' => '0', 'min_quantity' => '2', 'unit_cost' => '0',
        ];
    }

    /** @dataProvider badQuantities */
    public function test_entrada_rejects_bad_quantities(mixed $qty): void
    {
        $item = $this->makeItem();
        $before = InventoryMovement::query()->count();

        $this->post(route('inventario.movimientos.entrada'), [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => $qty, 'unit_cost' => '10',
        ])->assertAnyBagErrors();

        $this->assertSame($before, InventoryMovement::query()->count());
        $this->assertSame('10.0000', $this->onHand($item));
    }

    public static function badQuantities(): array
    {
        return [
            'vacio' => [''], 'cero' => ['0'], 'negativo' => ['-1'], 'cientifica' => ['1e2'], 'texto' => ['abc'],
            'cinco decimales' => ['1.00001'], 'once enteros' => ['12345678901'], 'coma decimal' => ['1,5'], 'coma decimal 2' => ['12,50'], 'miles mal agrupados' => ['1,00'],
            'array' => [['1']], 'espacio' => [' '], 'inf' => ['INF'],
        ];
    }

    public function test_thousands_separator_is_accepted_only_when_well_grouped(): void
    {
        $item = $this->makeItem(['initial_stock' => '0', 'unit_cost' => '0']);
        $this->entrada($item, '1,250', '10')->assertSessionHasNoErrors();
        $this->assertSame('1250.0000', $this->onHand($item));
    }

    public function test_money_like_errors_show_one_message(): void
    {
        $item = $this->makeItem();
        $this->entrada($item, 'abc');
        $this->assertCount(1, session('errors')->getBag('default')->get('quantity') ?: session('errors')->getBags()[array_key_first(session('errors')->getBags())]->get('quantity'));
    }

    public function test_item_creation_validation(): void
    {
        $this->post(route('inventario.items.store'), $this->itemPayload())->assertSessionHasNoErrors();

        // SKU duplicado (sin distinguir mayusculas), caracteres invalidos, largo.
        foreach (['res-a2', 'RES A2', 'RES<script>', str_repeat('A', 61), ''] as $sku) {
            $this->post(route('inventario.items.store'), $this->itemPayload(['name' => 'Otro', 'sku' => $sku]))
                ->assertAnyBagErrors('sku');
        }
        // Nombre, unidad, stock minimo, costo, sucursal.
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N1', 'name' => '']))->assertAnyBagErrors('name');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N2', 'name' => 'x']))->assertAnyBagErrors('name');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N3', 'unit_of_measure' => '']))->assertAnyBagErrors('unit_of_measure');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N4', 'min_quantity' => '-1']))->assertAnyBagErrors('min_quantity');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N5', 'initial_stock' => '5', 'unit_cost' => '']))->assertAnyBagErrors('unit_cost');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N6', 'branch_id' => (string) Str::uuid()]))->assertAnyBagErrors('branch_id');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N7', 'supplier_id' => (string) Str::uuid()]))->assertAnyBagErrors('supplier_id');
        $this->post(route('inventario.items.store'), $this->itemPayload(['sku' => 'N8', 'name' => str_repeat('a', 201)]))->assertAnyBagErrors('name');

        $this->assertSame(1, DB::table('inventory_items')->count());
    }

    public function test_xss_in_item_name_is_escaped_in_listing(): void
    {
        $this->post(route('inventario.items.store'), $this->itemPayload(['name' => '<script>alert(1)</script>', 'sku' => 'XSS1']))->assertSessionHasNoErrors();

        $html = $this->get(route('inventario.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_stock_never_goes_negative(): void
    {
        $item = $this->makeItem(['initial_stock' => '5']);

        $this->salida($item, '5.0001')->assertAnyBagErrors();
        $this->assertSame('5.0000', $this->onHand($item));

        $this->salida($item, '5')->assertSessionHasNoErrors();
        $this->assertSame('0.0000', $this->onHand($item));

        $this->salida($item, '0.0001')->assertAnyBagErrors();
        $this->post(route('inventario.movimientos.ajuste'), [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '-1', 'reason' => 'Merma',
        ])->assertAnyBagErrors();
        $this->assertSame('0.0000', $this->onHand($item));
    }

    public function test_ajuste_zero_is_rejected_cleanly(): void
    {
        $item = $this->makeItem();
        foreach (['0', '-0', '0.0000', '+0', '-0.0000'] as $q) {
            $r = $this->post(route('inventario.movimientos.ajuste'), [
                'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => $q, 'reason' => 'Ajuste cero',
            ]);
            $r->assertAnyBagErrors();
            $this->assertNotSame(500, $r->getStatusCode(), 'ajuste cero provoco 500: '.$q);
        }
        $this->assertSame('10.0000', $this->onHand($item));
    }

    public function test_salida_requires_reason_and_valid_references(): void
    {
        $item = $this->makeItem();
        foreach (['', 'ab', str_repeat('x', 501)] as $reason) {
            $this->salida($item, '1', ['reason' => $reason])->assertAnyBagErrors('reason');
        }
        $this->salida($item, '1', ['lot_id' => (string) Str::uuid()])->assertAnyBagErrors('lot_id');
        $this->salida($item, '1', ['encounter_id' => (string) Str::uuid()])->assertAnyBagErrors('encounter_id');
        $this->salida($item, '1', ['item_id' => (string) Str::uuid()])->assertAnyBagErrors('item_id');
        $this->assertSame('10.0000', $this->onHand($item));
    }

    public function test_inventario_inicial_only_once(): void
    {
        $item = $this->makeItem(['initial_stock' => '10']);
        $r = $this->post(route('inventario.movimientos.inicial'), [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '3', 'unit_cost' => '10',
        ]);
        $r->assertAnyBagErrors();
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame('10.0000', $this->onHand($item));
    }

    public function test_reversal_rules(): void
    {
        $item = $this->makeItem(['initial_stock' => '10']);
        $this->entrada($item, '4')->assertSessionHasNoErrors();
        $entrada = InventoryMovement::query()->where('movement_type', 'entrada')->firstOrFail();

        $this->post(route('inventario.movimientos.reversion', $entrada), ['reason' => 'Error de captura'])->assertSessionHasNoErrors();
        $this->assertSame('10.0000', $this->onHand($item));

        // Segunda reversion del mismo movimiento.
        $this->post(route('inventario.movimientos.reversion', $entrada), ['reason' => 'Otra vez'])->assertAnyBagErrors();
        // Reversion de la reversion.
        $rev = InventoryMovement::query()->where('movement_type', 'reversion')->firstOrFail();
        $this->post(route('inventario.movimientos.reversion', $rev), ['reason' => 'Revertir reversion'])->assertAnyBagErrors();
        $this->assertSame('10.0000', $this->onHand($item));

        // Revertir una entrada ya consumida dejaria stock negativo.
        $this->entrada($item, '5')->assertSessionHasNoErrors();
        $e2 = InventoryMovement::query()->where('movement_type', 'entrada')->where('quantity', '5.0000')->firstOrFail();
        $this->salida($item, '14')->assertSessionHasNoErrors();
        $r = $this->post(route('inventario.movimientos.reversion', $e2), ['reason' => 'Ya consumida']);
        $r->assertAnyBagErrors();
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame('1.0000', $this->onHand($item));

        // Motivo invalido.
        foreach (['', 'ab', str_repeat('x', 501)] as $reason) {
            $this->post(route('inventario.movimientos.reversion', $e2), ['reason' => $reason])->assertAnyBagErrors('reason');
        }
    }

    public function test_lots_and_expiry(): void
    {
        $item = $this->makeItem(['initial_stock' => '0', 'unit_cost' => '0']);

        // Caducidad sin lote y caducidad pasada.
        $this->entrada($item, '5', '10', ['expires_on' => now()->addMonth()->toDateString()])->assertAnyBagErrors('lot_code');
        $this->entrada($item, '5', '10', ['lot_code' => 'L1', 'expires_on' => now()->subDay()->toDateString()])->assertAnyBagErrors('expires_on');
        $this->entrada($item, '5', '10', ['lot_code' => 'L1', 'expires_on' => '31/12/2030'])->assertAnyBagErrors('expires_on');
        $this->entrada($item, '5', '10', ['lot_code' => 'L1<b>', 'expires_on' => now()->addMonth()->toDateString()])->assertAnyBagErrors('lot_code');

        $exp = now()->addMonth()->toDateString();
        $this->entrada($item, '5', '10', ['lot_code' => 'L1', 'expires_on' => $exp])->assertSessionHasNoErrors();
        // Mismo lote con otra caducidad.
        $this->entrada($item, '1', '10', ['lot_code' => 'L1', 'expires_on' => now()->addMonths(2)->toDateString()])->assertAnyBagErrors('expires_on');
        // Mismo lote, misma caducidad: suma.
        $this->entrada($item, '2', '10', ['lot_code' => 'L1', 'expires_on' => $exp])->assertSessionHasNoErrors();

        $lot = DB::table('inventory_lots')->where('item_id', $item->id)->first();
        $this->assertSame('7.0000', (string) $lot->quantity_on_hand);

        // Salida del lote mayor a lo disponible en el lote.
        $this->salida($item, '8', ['lot_id' => $lot->id])->assertAnyBagErrors();
        $this->salida($item, '3', ['lot_id' => $lot->id])->assertSessionHasNoErrors();
        $this->assertSame('4.0000', (string) DB::table('inventory_lots')->where('id', $lot->id)->value('quantity_on_hand'));
        $this->assertSame('4.0000', $this->onHand($item));
    }

    public function test_weighted_average_cost_and_value(): void
    {
        $item = $this->makeItem(['initial_stock' => '10', 'unit_cost' => '100']);
        $this->entrada($item, '10', '200')->assertSessionHasNoErrors();

        $stock = $this->stock($item);
        $this->assertSame('150.0000', (string) $stock->avg_unit_cost);
        $this->assertSame('20.0000', (string) $stock->quantity_on_hand);

        $this->salida($item, '5')->assertSessionHasNoErrors();
        $stock = $this->stock($item);
        $this->assertSame('150.0000', (string) $stock->avg_unit_cost, 'una salida no cambia el costo promedio');
        $this->assertSame('15.0000', (string) $stock->quantity_on_hand);
    }

    public function test_stock_equals_sum_of_movements(): void
    {
        $item = $this->makeItem(['initial_stock' => '7']);
        $this->entrada($item, '3.5')->assertSessionHasNoErrors();
        $this->salida($item, '2.25')->assertSessionHasNoErrors();
        $this->post(route('inventario.movimientos.ajuste'), [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '-0.75', 'reason' => 'Merma',
        ])->assertSessionHasNoErrors();
        $this->post(route('inventario.movimientos.ajuste'), [
            'item_id' => $item->id, 'branch_id' => $this->mainBranchId(), 'quantity' => '1', 'reason' => 'Conteo',
        ])->assertSessionHasNoErrors();

        $sum = (string) DB::table('inventory_movements')->where('item_id', $item->id)->sum('quantity');
        $this->assertSame(0, bccomp($sum, $this->onHand($item), 4));
        $this->assertSame('8.5000', $this->onHand($item));
    }

    public function test_supplier_validation(): void
    {
        $this->post(route('inventario.proveedores.store'), ['name' => 'MediSupply', 'contact_phone' => '5512345678', 'contact_email' => 'a@b.mx'])->assertSessionHasNoErrors();
        $this->post(route('inventario.proveedores.store'), ['name' => 'medisupply'])->assertAnyBagErrors('name');
        $this->post(route('inventario.proveedores.store'), ['name' => 'X'])->assertAnyBagErrors('name');
        $this->post(route('inventario.proveedores.store'), ['name' => 'Otro', 'contact_phone' => '123'])->assertAnyBagErrors('contact_phone');
        $this->post(route('inventario.proveedores.store'), ['name' => 'Otro', 'contact_email' => 'no-es-correo'])->assertAnyBagErrors('contact_email');
        $this->post(route('inventario.proveedores.store'), ['name' => 'Otro', 'contact_email' => str_repeat('a', 250).'@b.mx'])->assertAnyBagErrors('contact_email');
    }

    public function test_supplier_in_use_cannot_be_deleted(): void
    {
        $supplier = $this->makeSupplier('ProvConItems');
        $this->makeItem(['supplier_id' => $supplier->id]);

        $r = $this->delete(route('inventario.proveedores.destroy', $supplier));
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->post(route('inventario.items.store'), $this->itemPayload(['name' => '=1+1', 'sku' => 'F1', 'category' => '@SUM(A1)', 'location' => '+cmd']))->assertSessionHasNoErrors();

        $body = $this->get(route('inventario.export'))->assertOk()->streamedContent();
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

    public function test_index_filters_do_not_break_on_hostile_input(): void
    {
        $this->makeItem();
        foreach (['%', '_', "'; DROP TABLE inventory_items;--", '<script>', str_repeat('a', 500), '\\'] as $q) {
            $r = $this->get(route('inventario.index', ['q' => $q, 'search' => $q, 'category' => $q, 'branch' => $q, 'supplier' => $q, 'status' => $q, 'view' => $q, 'page' => $q]));
            $this->assertContains($r->getStatusCode(), [200, 302], 'estado inesperado '.$r->getStatusCode().' con '.$q);
        }
        $this->assertDatabaseCount('inventory_items', 1);
    }

    public function test_kardex_running_balance_matches_stock(): void
    {
        $item = $this->makeItem(['initial_stock' => '10']);
        $this->entrada($item, '5')->assertSessionHasNoErrors();
        $this->salida($item, '3')->assertSessionHasNoErrors();

        $html = $this->get(route('inventario.items.show', $item))->assertOk()->getContent();
        $this->assertStringContainsString('12', $html);
    }

    public function test_item_update_and_toggle_validation(): void
    {
        $item = $this->makeItem();
        $this->makeItem(['name' => 'Otro insumo', 'sku' => 'OTRO-1']);

        // SKU duplicado de otro insumo, pero propio permitido.
        $this->put(route('inventario.items.update', $item), ['name' => 'Guantes', 'sku' => 'OTRO-1', 'unit_of_measure' => 'Caja'])->assertAnyBagErrors('sku');
        $this->put(route('inventario.items.update', $item), ['name' => 'Guantes', 'sku' => 'GNT-NIT-M', 'unit_of_measure' => 'Caja'])->assertSessionHasNoErrors();

        // Un insumo inactivo no recibe movimientos.
        $this->patch(route('inventario.items.toggle', $item), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->entrada($item->fresh(), '1')->assertAnyBagErrors();
    }

    public function test_stock_settings_validation(): void
    {
        $item = $this->makeItem();
        foreach (['-1', 'abc', '1e3', '12345678901'] as $min) {
            $this->put(route('inventario.items.stock-settings', $item), ['branch_id' => $this->mainBranchId(), 'min_quantity' => $min])
                ->assertAnyBagErrors('min_quantity');
        }
        $this->put(route('inventario.items.stock-settings', $item), ['branch_id' => $this->mainBranchId(), 'min_quantity' => '3', 'location' => str_repeat('a', 101)])
            ->assertAnyBagErrors('location');
    }
}

<?php

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventario\InventarioService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos demo del inventario (los mismos 8 insumos del prototipo de Figma).
 *
 * Coherencia: el stock NUNCA se escribe a mano. Cada insumo recibe un historial de
 * movimientos (inventario inicial -> entrada -> salidas) y el trigger de la BD calcula
 * existencia y costo promedio; la existencia final coincide con la del prototipo.
 * Es idempotente: si el primer insumo ya existe, no hace nada.
 */
class DemoInventarioSeeder extends Seeder
{
    public function run(): void
    {
        if (InventoryItem::query()->where('sku', 'ANS-LID-002')->exists()) {
            return;
        }

        $branch = Branch::query()->orderByDesc('is_main')->first();
        $actor = User::query()->where('email', 'admin@dental.test')->first() ?? User::query()->first();
        if (! $branch || ! $actor) {
            return;
        }

        $service = app(InventarioService::class);

        $suppliers = [];
        foreach ([
            'MediSupply' => ['5512345678', 'ventas@medisupply.mx'],
            'Dental Pro México' => ['3398765432', 'pedidos@dentalpro.mx'],
            'Distribuidora Dental del Centro' => ['5587654321', 'contacto@dentalcentro.mx'],
        ] as $name => [$phone, $email]) {
            $suppliers[$name] = Supplier::query()->firstOrCreate(
                ['name' => $name],
                ['contact_phone' => $phone, 'contact_email' => $email, 'status' => RecordStatus::Active->value]
            );
        }
        // Un proveedor inactivo para mostrar el filtro de estado.
        Supplier::query()->firstOrCreate(['name' => 'Insumos Odonto (descontinuado)'], ['status' => RecordStatus::Inactive->value]);

        $encounterId = ClinicalEncounter::query()->orderByDesc('occurred_at')->value('id');

        // sku => [nombre, categoria, unidad, proveedor, existencia final, minimo, costo, ubicacion]
        $catalog = [
            ['ANS-LID-002', 'Anestesia Lidocaína', 'Anestésicos', 'cartucho', 'MediSupply', 8, 20, '18.50', 'Estante A2'],
            ['GNT-NIT-M', 'Guantes nitrilo (M)', 'Protección', 'Caja', 'Dental Pro México', 2, 10, '165.00', 'Gaveta B-1'],
            ['RES-A2-3M', 'Resina compuesta A2', 'Restaurativo', 'Jeringa', 'Dental Pro México', 1, 5, '420.00', 'Refrigerador'],
            ['FR-DIA-12', 'Fresas diamante surtidas', 'Instrumental', 'Paquete', 'Distribuidora Dental del Centro', 0, 5, '240.00', 'Gaveta C-3'],
            ['ALG-R2-500', 'Algodones rollos', 'Consumibles', 'Paquete', 'MediSupply', 45, 20, '85.00', 'Estante A-1'],
            ['CMT-ION-15', 'Cemento ionómero', 'Restaurativo', 'Frasco', 'Dental Pro México', 12, 5, '310.00', 'Estante A-3'],
            ['EYC-SAL-100', 'Eyectores de saliva', 'Consumibles', 'Bolsa', 'MediSupply', 180, 50, '120.00', 'Gaveta B-2'],
            ['SUT-40-12', 'Hilo de sutura 4-0', 'Cirugía', 'cartucho', 'Distribuidora Dental del Centro', 8, 10, '18.50', 'Estante A2'],
        ];

        foreach ($catalog as $idx => [$sku, $name, $category, $unit, $supplierName, $target, $min, $cost, $location]) {
            DB::transaction(function () use ($service, $branch, $actor, $suppliers, $encounterId, $idx, $sku, $name, $category, $unit, $supplierName, $target, $min, $cost, $location) {
                $item = InventoryItem::create([
                    'name' => $name,
                    'sku' => $sku,
                    'category' => $category,
                    'unit_of_measure' => $unit,
                    'supplier_id' => $suppliers[$supplierName]->id,
                    'status' => RecordStatus::Active->value,
                ]);
                $service->saveStockSettings($item, $branch->id, (string) $min, $location);

                // Historial: inicial -> entrada(6) -> salida(5) -> salida(3); termina en $target.
                $in = 6;
                $out1 = 5;
                $out2 = 3;
                $initial = $target + $out1 + $out2 - $in;
                $base = 70 - ($idx * 3);

                $make = function (InventoryMovementType $type, string $qty, int $daysAgo, ?string $unitCost, array $extra = []) use ($item, $branch, $actor, $idx) {
                    return InventoryMovement::create(array_merge([
                        'branch_id' => $branch->id,
                        'item_id' => $item->id,
                        'movement_type' => $type->value,
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'occurred_at' => now()->subDays($daysAgo)->setTime(9, 15 + $idx),
                        'actor_user_id' => $actor->id,
                    ], $extra));
                };

                if ($initial > 0) {
                    $make(InventoryMovementType::InventarioInicial, (string) $initial, $base, $cost, ['reason' => 'Inventario inicial']);
                }

                // Lotes de ejemplo: uno por caducar (lidocaína), uno vigente (resina) y uno vencido (cemento).
                $lotId = null;
                $expires = match ($sku) {
                    'ANS-LID-002' => now()->addDays(20)->toDateString(),
                    'RES-A2-3M' => now()->addDays(120)->toDateString(),
                    'CMT-ION-15' => now()->subDays(5)->toDateString(),
                    default => null,
                };
                if ($expires && $target > 0) {
                    $lot = InventoryLot::create([
                        'branch_id' => $branch->id,
                        'item_id' => $item->id,
                        'lot_code' => 'L' . now()->format('ym') . '-' . substr($sku, 0, 3),
                        'expires_on' => $expires,
                        'quantity_on_hand' => (string) min($in, $target),
                    ]);
                    $lotId = $lot->id;
                }

                $make(InventoryMovementType::Entrada, (string) $in, $base - 20, $cost, [
                    'reference' => 'FAC-' . (1200 + $idx),
                    'reason' => 'Compra a ' . $supplierName,
                    'lot_id' => $lotId,
                ]);
                $make(InventoryMovementType::Salida, '-' . $out1, $base - 35, null, ['reason' => 'Uso en consultas de la semana']);
                $make(InventoryMovementType::Salida, '-' . $out2, 6 + $idx, null, array_filter([
                    'reason' => 'Uso en consulta',
                    'encounter_id' => $sku === 'GNT-NIT-M' ? $encounterId : null,
                ]));
            });
        }
    }
}

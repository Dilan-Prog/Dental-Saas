<?php

namespace App\Services\Inventario;

use App\Enums\InventoryMovementType;
use App\Enums\RecordStatus;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escritura del inventario.
 *
 * Reglas de la BD que se respetan (no se saltan nunca):
 *  - inventory_movements es inmutable (triggers): solo INSERT. Se corrige con ajuste o reversion.
 *  - El stock (inventory_stock.quantity_on_hand y avg_unit_cost) lo actualiza el trigger
 *    inventory_movements_ai; aqui NUNCA se escribe esa cantidad. Solo se guarda
 *    min_quantity y location, que son configuracion.
 *  - CHECK quantity_on_hand >= 0: se valida antes (mensaje claro) y se captura el CHECK como respaldo.
 *  - Un solo inventario_inicial por sucursal + insumo (columna generada con UNIQUE).
 *  - inventory_lots.quantity_on_hand NO lo toca ningun trigger: se lleva aqui, en la misma transaccion.
 *
 * Cantidades y costos viajan como string decimal (4 decimales) para no perder precision.
 */
class InventarioService
{
    /** Nombre de la bolsa de errores por tipo de formulario (ver los FormRequest). */
    public const BAG_ITEM = 'insumo';

    // ------------------------------------------------------------------
    // Insumos
    // ------------------------------------------------------------------

    /**
     * Alta de insumo + configuracion de stock en la sucursal + inventario inicial (opcional).
     *
     * @param  array{name:string,sku:string,category?:?string,unit_of_measure:string,supplier_id?:?string,
     *               branch_id:string,min_quantity?:?string,location?:?string,initial_stock?:?string,unit_cost?:?string} $d
     */
    public function createItem(array $d): InventoryItem
    {
        return $this->transaction(self::BAG_ITEM, function () use ($d) {
            $item = InventoryItem::create([
                'name' => $d['name'],
                'sku' => $d['sku'],
                'category' => $this->nullIfBlank($d['category'] ?? null),
                'unit_of_measure' => $d['unit_of_measure'],
                'supplier_id' => $this->nullIfBlank($d['supplier_id'] ?? null),
                'status' => RecordStatus::Active->value,
            ]);

            $this->saveStockSettings(
                $item,
                $d['branch_id'],
                $this->dec($d['min_quantity'] ?? '0'),
                $this->nullIfBlank($d['location'] ?? null),
            );

            $initial = $this->dec($d['initial_stock'] ?? '0');
            if (bccomp($initial, '0', 4) > 0) {
                $this->insertMovement(
                    InventoryMovementType::InventarioInicial,
                    $item->id,
                    $d['branch_id'],
                    $initial,
                    $this->dec($d['unit_cost'] ?? '0'),
                    ['reason' => 'Inventario inicial'],
                );
            }

            return $item;
        });
    }

    /** @param array{name:string,sku:string,category?:?string,unit_of_measure:string,supplier_id?:?string} $d */
    public function updateItem(InventoryItem $item, array $d): InventoryItem
    {
        return $this->transaction(self::BAG_ITEM, function () use ($item, $d) {
            $item->update([
                'name' => $d['name'],
                'sku' => $d['sku'],
                'category' => $this->nullIfBlank($d['category'] ?? null),
                'unit_of_measure' => $d['unit_of_measure'],
                'supplier_id' => $this->nullIfBlank($d['supplier_id'] ?? null),
            ]);

            return $item;
        });
    }

    /** Minimo y ubicacion por sucursal (no toca la existencia). */
    public function saveStockSettings(InventoryItem $item, string $branchId, string $min, ?string $location): void
    {
        DB::table('inventory_stock')->updateOrInsert(
            [
                'organization_id' => $item->organization_id,
                'branch_id' => $branchId,
                'item_id' => $item->id,
            ],
            [
                'min_quantity' => $this->dec($min),
                'location' => $this->nullIfBlank($location),
                'updated_at' => now(),
            ]
        );
    }

    public function toggleItem(InventoryItem $item): InventoryItem
    {
        $item->status = $item->status === RecordStatus::Active ? RecordStatus::Inactive : RecordStatus::Active;
        $item->save();

        return $item;
    }

    // ------------------------------------------------------------------
    // Movimientos
    // ------------------------------------------------------------------

    /**
     * @param  array{item_id:string,branch_id:string,quantity:string,unit_cost:string,reference?:?string,
     *               supplier_id?:?string,lot_code?:?string,expires_on?:?string,encounter_id?:?string} $d
     */
    public function entrada(array $d, string $bag = 'entrada'): InventoryMovement
    {
        return $this->transaction($bag, function () use ($d, $bag) {
            $item = $this->lockItem($d['item_id']);
            $qty = $this->dec($d['quantity']);

            $lot = null;
            if (filled($d['lot_code'] ?? null)) {
                $lot = $this->upsertLot($item->id, $d['branch_id'], trim($d['lot_code']), $d['expires_on'] ?? null, $qty, $bag);
            }

            $reason = null;
            if (filled($d['supplier_id'] ?? null)) {
                $supplier = Supplier::query()->find($d['supplier_id']);
                if ($supplier) {
                    $reason = 'Compra a ' . $supplier->name;
                    if (! $item->supplier_id) {
                        $item->update(['supplier_id' => $supplier->id]);
                    }
                }
            }

            return $this->insertMovement(
                InventoryMovementType::Entrada,
                $item->id,
                $d['branch_id'],
                $qty,
                $this->dec($d['unit_cost']),
                [
                    'reference' => $this->nullIfBlank($d['reference'] ?? null),
                    'reason' => $reason,
                    'encounter_id' => $this->nullIfBlank($d['encounter_id'] ?? null),
                    'lot_id' => $lot?->id,
                ],
            );
        });
    }

    /**
     * @param  array{item_id:string,branch_id:string,quantity:string,reason:string,reference?:?string,
     *               lot_id?:?string,encounter_id?:?string} $d
     */
    public function salida(array $d, string $bag = 'salida'): InventoryMovement
    {
        return $this->transaction($bag, function () use ($d, $bag) {
            $item = $this->lockItem($d['item_id']);
            $qty = $this->dec($d['quantity']);
            $signed = bcmul($qty, '-1', 4);

            $this->assertStockAvailable($item, $d['branch_id'], $signed, $bag);

            $lotId = $this->nullIfBlank($d['lot_id'] ?? null);
            if ($lotId) {
                $this->applyLotDelta($lotId, $item->id, $d['branch_id'], $signed, $bag);
            }

            return $this->insertMovement(
                InventoryMovementType::Salida,
                $item->id,
                $d['branch_id'],
                $signed,
                null,
                [
                    'reference' => $this->nullIfBlank($d['reference'] ?? null),
                    'reason' => $d['reason'],
                    'encounter_id' => $this->nullIfBlank($d['encounter_id'] ?? null),
                    'lot_id' => $lotId,
                ],
            );
        });
    }

    /**
     * Ajuste con signo (positivo suma, negativo resta). Motivo obligatorio.
     *
     * @param  array{item_id:string,branch_id:string,quantity:string,reason:string,unit_cost?:?string,
     *               reference?:?string,lot_id?:?string,encounter_id?:?string} $d
     */
    public function ajuste(array $d, string $bag = 'ajuste'): InventoryMovement
    {
        return $this->transaction($bag, function () use ($d, $bag) {
            $item = $this->lockItem($d['item_id']);
            $signed = $this->dec($d['quantity']);

            $lotId = null;
            $cost = null;

            if (bccomp($signed, '0', 4) < 0) {
                $this->assertStockAvailable($item, $d['branch_id'], $signed, $bag);
                $lotId = $this->nullIfBlank($d['lot_id'] ?? null);
                if ($lotId) {
                    $this->applyLotDelta($lotId, $item->id, $d['branch_id'], $signed, $bag);
                }
            } elseif (filled($d['unit_cost'] ?? null)) {
                $cost = $this->dec($d['unit_cost']);
            }

            return $this->insertMovement(
                InventoryMovementType::Ajuste,
                $item->id,
                $d['branch_id'],
                $signed,
                $cost,
                [
                    'reference' => $this->nullIfBlank($d['reference'] ?? null),
                    'reason' => $d['reason'],
                    'encounter_id' => $this->nullIfBlank($d['encounter_id'] ?? null),
                    'lot_id' => $lotId,
                ],
            );
        });
    }

    /** Inventario inicial de un insumo que aun no lo tiene en esa sucursal (una sola vez). */
    public function inicial(array $d, string $bag = 'inicial'): InventoryMovement
    {
        return $this->transaction($bag, function () use ($d, $bag) {
            $item = $this->lockItem($d['item_id']);

            $exists = InventoryMovement::query()
                ->where('item_id', $item->id)
                ->where('branch_id', $d['branch_id'])
                ->where('movement_type', InventoryMovementType::InventarioInicial->value)
                ->exists();

            if ($exists) {
                throw $this->fail($bag, 'item_id', 'Este insumo ya tiene inventario inicial en la sucursal. Registre una entrada o un ajuste.');
            }

            return $this->insertMovement(
                InventoryMovementType::InventarioInicial,
                $item->id,
                $d['branch_id'],
                $this->dec($d['quantity']),
                $this->dec($d['unit_cost']),
                ['reason' => 'Inventario inicial', 'reference' => $this->nullIfBlank($d['reference'] ?? null)],
            );
        });
    }

    /** Movimiento contrario con reverses_movement_id; solo una vez por movimiento. */
    public function reversion(InventoryMovement $movement, string $reason, string $bag = 'reversion'): InventoryMovement
    {
        return $this->transaction($bag, function () use ($movement, $reason, $bag) {
            /** @var InventoryMovement $orig */
            $orig = InventoryMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($orig->movement_type === InventoryMovementType::Reversion) {
                throw $this->fail($bag, 'reason', 'Una reversión no se puede revertir; registre un ajuste.');
            }
            if ($orig->movement_type === InventoryMovementType::InventarioInicial) {
                throw $this->fail($bag, 'reason', 'El inventario inicial no se revierte; corrija con un ajuste.');
            }
            if (InventoryMovement::query()->where('reverses_movement_id', $orig->id)->exists()) {
                throw $this->fail($bag, 'reason', 'Este movimiento ya fue revertido.');
            }

            $item = $this->lockItem($orig->item_id, requireActive: false);
            $signed = bcmul($this->dec((string) $orig->quantity), '-1', 4);

            if (bccomp($signed, '0', 4) < 0) {
                $this->assertStockAvailable($item, $orig->branch_id, $signed, $bag, 'reason');
            }
            if ($orig->lot_id) {
                $this->applyLotDelta($orig->lot_id, $orig->item_id, $orig->branch_id, $signed, $bag, 'reason');
            }

            return $this->insertMovement(
                InventoryMovementType::Reversion,
                $orig->item_id,
                $orig->branch_id,
                $signed,
                null,
                [
                    'reason' => $reason,
                    'reference' => 'Reversión de ' . $orig->movement_type->label(),
                    'lot_id' => $orig->lot_id,
                    'encounter_id' => $orig->encounter_id,
                    'reverses_movement_id' => $orig->id,
                ],
            );
        });
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function insertMovement(InventoryMovementType $type, string $itemId, string $branchId, string $signedQty, ?string $unitCost, array $extra = []): InventoryMovement
    {
        return InventoryMovement::create(array_merge([
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'movement_type' => $type->value,
            'quantity' => $signedQty,
            'unit_cost' => $unitCost,
            'occurred_at' => now(),
            'actor_user_id' => Auth::id(),
        ], $extra));
    }

    private function lockItem(string $itemId, bool $requireActive = true): InventoryItem
    {
        /** @var InventoryItem $item */
        $item = InventoryItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

        if ($requireActive && $item->status !== RecordStatus::Active) {
            throw ValidationException::withMessages(['item_id' => 'El insumo está inactivo; actívelo para registrar movimientos.']);
        }

        return $item;
    }

    private function assertStockAvailable(InventoryItem $item, string $branchId, string $signedDelta, string $bag, string $field = 'quantity'): void
    {
        $row = DB::table('inventory_stock')
            ->where('organization_id', $item->organization_id)
            ->where('branch_id', $branchId)
            ->where('item_id', $item->id)
            ->lockForUpdate()
            ->first();

        $onHand = $this->dec((string) ($row->quantity_on_hand ?? '0'));

        if (bccomp(bcadd($onHand, $signedDelta, 4), '0', 4) < 0) {
            throw $this->fail($bag, $field, sprintf(
                'Stock insuficiente: hay %s %s disponibles en la sucursal.',
                $this->trimDecimals($onHand),
                $item->unit_of_measure
            ));
        }
    }

    /** Crea o suma un lote en la entrada; respeta la caducidad ya registrada del lote. */
    private function upsertLot(string $itemId, string $branchId, string $code, ?string $expiresOn, string $qty, string $bag): InventoryLot
    {
        $lot = InventoryLot::query()
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('lot_code', $code)
            ->lockForUpdate()
            ->first();

        if (! $lot) {
            return InventoryLot::create([
                'branch_id' => $branchId,
                'item_id' => $itemId,
                'lot_code' => $code,
                'expires_on' => $expiresOn ?: null,
                'quantity_on_hand' => $qty,
            ]);
        }

        if ($expiresOn && $lot->expires_on && $lot->expires_on->toDateString() !== $expiresOn) {
            throw $this->fail($bag, 'expires_on', 'El lote ' . $code . ' ya existe con caducidad ' . $lot->expires_on->format('d/m/Y') . '.');
        }

        $lot->quantity_on_hand = bcadd($this->dec((string) $lot->quantity_on_hand), $qty, 4);
        if ($expiresOn && ! $lot->expires_on) {
            $lot->expires_on = $expiresOn;
        }
        $lot->save();

        return $lot;
    }

    /** Suma/resta existencia de un lote sin dejarla negativa y validando que sea del insumo y sucursal. */
    private function applyLotDelta(string $lotId, string $itemId, string $branchId, string $signedDelta, string $bag, string $field = 'lot_id'): void
    {
        $lot = InventoryLot::query()->whereKey($lotId)->lockForUpdate()->first();

        if (! $lot || $lot->item_id !== $itemId || $lot->branch_id !== $branchId) {
            throw $this->fail($bag, $field, 'El lote no corresponde al insumo o a la sucursal.');
        }

        $new = bcadd($this->dec((string) $lot->quantity_on_hand), $signedDelta, 4);
        if (bccomp($new, '0', 4) < 0) {
            throw $this->fail($bag, $field, sprintf('El lote %s solo tiene %s disponibles.', $lot->lot_code, $this->trimDecimals((string) $lot->quantity_on_hand)));
        }

        $lot->quantity_on_hand = $new;
        $lot->save();
    }

    /** Ejecuta en transaccion y traduce errores de la BD (CHECK, UNIQUE, triggers) a mensajes claros. */
    private function transaction(string $bag, \Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            [$field, $message] = $this->friendlyDbError($e);

            if ($message === null) {
                throw $e;
            }

            throw ValidationException::withMessages([$field => $message])->errorBag($bag);
        }
    }

    /** @return array{0:string,1:?string} [campo, mensaje] */
    public function friendlyDbError(QueryException $e): array
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'chk_inventory_stock_qty') || str_contains($msg, 'inventory_stock')) {
            if (str_contains($msg, 'Check constraint')) {
                return ['quantity', 'Stock insuficiente: la operación dejaría la existencia en negativo.'];
            }
        }
        if (str_contains($msg, 'uq_inv_mov_initial')) {
            return ['item_id', 'Este insumo ya tiene inventario inicial en la sucursal. Registre una entrada o un ajuste.'];
        }
        if (str_contains($msg, 'uq_inventory_items_org_sku')) {
            return ['sku', 'Ya existe un insumo con ese SKU.'];
        }
        if (str_contains($msg, 'uq_inventory_lots_branch_item_lot')) {
            return ['lot_code', 'Ese código de lote ya existe para el insumo en la sucursal.'];
        }
        if (str_contains($msg, 'chk_inventory_movements_qty')) {
            return ['quantity', 'La cantidad no puede ser cero.'];
        }
        if (str_contains($msg, 'inventory_movements es inmutable')) {
            return ['quantity', 'Los movimientos no se editan ni se eliminan; use un ajuste o una reversión.'];
        }
        if (str_contains($msg, 'Out of range') || str_contains($msg, 'Data too long')) {
            return ['quantity', 'Algún valor excede el tamaño permitido.'];
        }

        return ['quantity', null];
    }

    private function fail(string $bag, string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message])->errorBag($bag);
    }

    /** Normaliza a string decimal de 4 decimales. */
    private function dec(string|int|float|null $value): string
    {
        $v = trim((string) ($value ?? '0'));

        return bcadd($v === '' ? '0' : $v, '0', 4);
    }

    private function trimDecimals(string $value): string
    {
        $v = rtrim(rtrim($this->dec($value), '0'), '.');

        return $v === '' ? '0' : $v;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

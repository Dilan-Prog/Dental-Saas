<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el trigger inventory_movements_ai (migracion 000008).
 *
 * Problema: con cantidad negativa, el INSERT ... ON DUPLICATE KEY UPDATE del trigger propone
 * una fila con quantity_on_hand < 0 y MySQL aplica el CHECK (quantity_on_hand >= 0) a esa fila
 * ANTES de resolver el duplicado, asi que NINGUNA salida/ajuste negativo podia registrarse
 * aunque hubiera existencias.
 *
 * Solucion: las cantidades negativas hacen UPDATE directo de la fila existente (el CHECK
 * solo falla si realmente quedaria en negativo) y, si no hay fila de stock, se rechaza.
 * Las positivas conservan la logica original (alta de fila + costo promedio ponderado).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_ai');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_movements_ai AFTER INSERT ON inventory_movements
FOR EACH ROW
BEGIN
    IF NEW.quantity < 0 THEN
        UPDATE inventory_stock
           SET quantity_on_hand = quantity_on_hand + NEW.quantity
         WHERE branch_id = NEW.branch_id AND item_id = NEW.item_id;
        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock insuficiente: el insumo no tiene existencias en la sucursal';
        END IF;
    ELSE
        INSERT INTO inventory_stock (organization_id, branch_id, item_id, quantity_on_hand, avg_unit_cost)
        VALUES (
            NEW.organization_id, NEW.branch_id, NEW.item_id, NEW.quantity,
            IF(NEW.quantity > 0, IFNULL(NEW.unit_cost, 0), 0)
        )
        ON DUPLICATE KEY UPDATE
            avg_unit_cost = IF(
                NEW.quantity > 0 AND NEW.unit_cost IS NOT NULL AND (quantity_on_hand + NEW.quantity) > 0,
                (quantity_on_hand * avg_unit_cost + NEW.quantity * NEW.unit_cost) / (quantity_on_hand + NEW.quantity),
                avg_unit_cost
            ),
            quantity_on_hand = quantity_on_hand + NEW.quantity;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_ai');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_movements_ai AFTER INSERT ON inventory_movements
FOR EACH ROW
BEGIN
    INSERT INTO inventory_stock (organization_id, branch_id, item_id, quantity_on_hand, avg_unit_cost)
    VALUES (
        NEW.organization_id, NEW.branch_id, NEW.item_id, NEW.quantity,
        IF(NEW.quantity > 0, IFNULL(NEW.unit_cost, 0), 0)
    )
    ON DUPLICATE KEY UPDATE
        avg_unit_cost = IF(
            NEW.quantity > 0 AND NEW.unit_cost IS NOT NULL AND (quantity_on_hand + NEW.quantity) > 0,
            (quantity_on_hand * avg_unit_cost + NEW.quantity * NEW.unit_cost) / (quantity_on_hand + NEW.quantity),
            avg_unit_cost
        ),
        quantity_on_hand = quantity_on_hand + NEW.quantity;
END
SQL);
    }
};

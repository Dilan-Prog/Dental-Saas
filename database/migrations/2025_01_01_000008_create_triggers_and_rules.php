<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Reglas de negocio que no se pueden expresar de forma declarativa (MySQL 8.0).
 * - Un trigger por evento (_bi / _bu / _bd / _ai / _au), una sentencia DB::unprepared por trigger.
 * - Los triggers anti-empalme de citas los crea la migración 04 (no se duplican aquí).
 * - updated_at lo maneja useCurrentOnUpdate en las migraciones de tablas.
 * - Row Level Security no existe en MySQL: el aislamiento por organization_id es responsabilidad de la app.
 */
return new class extends Migration
{
    /** @var array<string, string> nombre => CREATE TRIGGER */
    private function triggers(): array
    {
        $t = [];

        // ---------- documentos firmados inmutables ----------
        foreach (['clinical_notes' => 'clinical_notes', 'prescriptions' => 'prescriptions'] as $table => $prefix) {
            $t["{$prefix}_signed_bu"] = <<<SQL
CREATE TRIGGER {$prefix}_signed_bu BEFORE UPDATE ON {$table}
FOR EACH ROW
BEGIN
    IF OLD.status = 'firmada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento firmado: es inmutable; use adendas o cree un documento nuevo';
    END IF;
END
SQL;
            $t["{$prefix}_signed_bd"] = <<<SQL
CREATE TRIGGER {$prefix}_signed_bd BEFORE DELETE ON {$table}
FOR EACH ROW
BEGIN
    IF OLD.status = 'firmada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento firmado: no se puede eliminar';
    END IF;
END
SQL;
        }

        // conceptos de receta firmada
        $t['prescription_items_signed_bi'] = <<<'SQL'
CREATE TRIGGER prescription_items_signed_bi BEFORE INSERT ON prescription_items
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    SELECT status INTO v_status FROM prescriptions
     WHERE organization_id = NEW.organization_id AND id = NEW.prescription_id;
    IF v_status = 'firmada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receta firmada: no se pueden agregar conceptos';
    END IF;
END
SQL;
        $t['prescription_items_signed_bu'] = <<<'SQL'
CREATE TRIGGER prescription_items_signed_bu BEFORE UPDATE ON prescription_items
FOR EACH ROW
BEGIN
    DECLARE v_old_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_new_status VARCHAR(20) DEFAULT NULL;
    SELECT status INTO v_old_status FROM prescriptions
     WHERE organization_id = OLD.organization_id AND id = OLD.prescription_id;
    SELECT status INTO v_new_status FROM prescriptions
     WHERE organization_id = NEW.organization_id AND id = NEW.prescription_id;
    IF v_old_status = 'firmada' OR v_new_status = 'firmada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receta firmada: los conceptos son inmutables';
    END IF;
END
SQL;
        $t['prescription_items_signed_bd'] = <<<'SQL'
CREATE TRIGGER prescription_items_signed_bd BEFORE DELETE ON prescription_items
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    SELECT status INTO v_status FROM prescriptions
     WHERE organization_id = OLD.organization_id AND id = OLD.prescription_id;
    IF v_status = 'firmada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receta firmada: no se pueden eliminar conceptos';
    END IF;
END
SQL;

        // ---------- inventario ----------
        $t['inventory_movements_bu'] = <<<'SQL'
CREATE TRIGGER inventory_movements_bu BEFORE UPDATE ON inventory_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_movements es inmutable; corrija con un ajuste o una reversion';
END
SQL;
        $t['inventory_movements_bd'] = <<<'SQL'
CREATE TRIGGER inventory_movements_bd BEFORE DELETE ON inventory_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_movements es inmutable; corrija con un ajuste o una reversion';
END
SQL;
        // OJO: en MySQL las asignaciones de ON DUPLICATE KEY UPDATE se evalúan de izquierda a derecha;
        // avg_unit_cost debe ir ANTES que quantity_on_hand para usar la cantidad previa.
        $t['inventory_movements_ai'] = <<<'SQL'
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
SQL;

        // ---------- caja cerrada inmutable ----------
        $t['cash_sessions_closed_bu'] = <<<'SQL'
CREATE TRIGGER cash_sessions_closed_bu BEFORE UPDATE ON cash_sessions
FOR EACH ROW
BEGIN
    IF OLD.status = 'cerrada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sesion de caja cerrada: use cash_session_adjustments';
    END IF;
END
SQL;
        $t['cash_sessions_closed_bd'] = <<<'SQL'
CREATE TRIGGER cash_sessions_closed_bd BEFORE DELETE ON cash_sessions
FOR EACH ROW
BEGIN
    IF OLD.status = 'cerrada' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sesion de caja cerrada: no se puede eliminar';
    END IF;
END
SQL;

        // ---------- rol de administrador del sistema ----------
        $t['roles_system_admin_bu'] = <<<'SQL'
CREATE TRIGGER roles_system_admin_bu BEFORE UPDATE ON roles
FOR EACH ROW
BEGIN
    IF OLD.is_system_admin = 1 AND (
           NOT (NEW.code <=> OLD.code)
        OR NOT (NEW.status <=> OLD.status)
        OR NEW.is_system_admin <> OLD.is_system_admin
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El rol de administrador del sistema no es editable ni desactivable';
    END IF;
END
SQL;
        $t['roles_system_admin_bd'] = <<<'SQL'
CREATE TRIGGER roles_system_admin_bd BEFORE DELETE ON roles
FOR EACH ROW
BEGIN
    IF OLD.is_system_admin = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El rol de administrador del sistema no se puede eliminar';
    END IF;
END
SQL;

        // ---------- último administrador activo ----------
        $t['organization_members_last_admin_bu'] = <<<'SQL'
CREATE TRIGGER organization_members_last_admin_bu BEFORE UPDATE ON organization_members
FOR EACH ROW
BEGIN
    DECLARE v_was_admin INT DEFAULT 0;
    DECLARE v_stays_admin INT DEFAULT 0;
    DECLARE v_others INT DEFAULT 0;
    DECLARE v_lock CHAR(36) DEFAULT NULL;

    IF OLD.is_active = 1 AND OLD.role_id IS NOT NULL THEN
        SELECT COUNT(*) INTO v_was_admin FROM roles
         WHERE organization_id = OLD.organization_id AND id = OLD.role_id AND is_system_admin = 1;
    END IF;

    IF v_was_admin > 0 THEN
        IF NEW.is_active = 1 AND NEW.role_id IS NOT NULL THEN
            SELECT COUNT(*) INTO v_stays_admin FROM roles
             WHERE organization_id = NEW.organization_id AND id = NEW.role_id AND is_system_admin = 1;
        END IF;

        IF v_stays_admin = 0 THEN
            SELECT id INTO v_lock FROM organizations WHERE id = OLD.organization_id FOR UPDATE;
            SELECT COUNT(*) INTO v_others
              FROM organization_members m
              JOIN roles r ON r.organization_id = m.organization_id AND r.id = m.role_id
             WHERE m.organization_id = OLD.organization_id
               AND m.id <> OLD.id
               AND m.is_active = 1
               AND r.is_system_admin = 1
               AND r.status = 'active';
            IF v_others = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La organizacion debe conservar al menos un administrador activo';
            END IF;
        END IF;
    END IF;
END
SQL;
        $t['organization_members_last_admin_bd'] = <<<'SQL'
CREATE TRIGGER organization_members_last_admin_bd BEFORE DELETE ON organization_members
FOR EACH ROW
BEGIN
    DECLARE v_was_admin INT DEFAULT 0;
    DECLARE v_others INT DEFAULT 0;
    DECLARE v_lock CHAR(36) DEFAULT NULL;

    IF OLD.is_active = 1 AND OLD.role_id IS NOT NULL THEN
        SELECT COUNT(*) INTO v_was_admin FROM roles
         WHERE organization_id = OLD.organization_id AND id = OLD.role_id AND is_system_admin = 1;
    END IF;

    IF v_was_admin > 0 THEN
        SELECT id INTO v_lock FROM organizations WHERE id = OLD.organization_id FOR UPDATE;
        SELECT COUNT(*) INTO v_others
          FROM organization_members m
          JOIN roles r ON r.organization_id = m.organization_id AND r.id = m.role_id
         WHERE m.organization_id = OLD.organization_id
           AND m.id <> OLD.id
           AND m.is_active = 1
           AND r.is_system_admin = 1
           AND r.status = 'active';
        IF v_others = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La organizacion debe conservar al menos un administrador activo';
        END IF;
    END IF;
END
SQL;

        // ---------- revocar sesiones al cambiar rol / activar-desactivar ----------
        $t['organization_members_revoke_au'] = <<<'SQL'
CREATE TRIGGER organization_members_revoke_au AFTER UPDATE ON organization_members
FOR EACH ROW
BEGIN
    IF NOT (OLD.role_id <=> NEW.role_id) OR OLD.is_active <> NEW.is_active THEN
        UPDATE user_sessions
           SET revoked_at = NOW(),
               revoked_reason = IF(NEW.is_active = 0, 'deactivated', 'role_changed')
         WHERE user_id = NEW.user_id
           AND organization_id = NEW.organization_id
           AND revoked_at IS NULL;
    END IF;
END
SQL;

        // ---------- aplicaciones de pago ----------
        $paymentBody = <<<'SQL'
FOR EACH ROW
BEGIN
    DECLARE v_invoice_total DECIMAL(14,2) DEFAULT NULL;
    DECLARE v_payment_amount DECIMAL(14,2) DEFAULT NULL;
    DECLARE v_invoice_applied DECIMAL(14,2) DEFAULT 0;
    DECLARE v_payment_applied DECIMAL(14,2) DEFAULT 0;

    IF NEW.reversed_at IS NULL THEN
        SELECT total INTO v_invoice_total FROM invoices
         WHERE organization_id = NEW.organization_id AND id = NEW.invoice_id
           FOR UPDATE;
        SELECT amount INTO v_payment_amount FROM payments
         WHERE organization_id = NEW.organization_id AND id = NEW.payment_id
           FOR UPDATE;

        SELECT COALESCE(SUM(applied_amount), 0) INTO v_invoice_applied FROM payment_applications
         WHERE organization_id = NEW.organization_id
           AND invoice_id = NEW.invoice_id
           AND reversed_at IS NULL
           AND NOT (id <=> NEW.id);

        SELECT COALESCE(SUM(applied_amount), 0) INTO v_payment_applied FROM payment_applications
         WHERE organization_id = NEW.organization_id
           AND payment_id = NEW.payment_id
           AND reversed_at IS NULL
           AND NOT (id <=> NEW.id);

        IF v_invoice_total IS NULL OR v_invoice_applied + NEW.applied_amount > v_invoice_total THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las aplicaciones exceden el total de la factura';
        END IF;
        IF v_payment_amount IS NULL OR v_payment_applied + NEW.applied_amount > v_payment_amount THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las aplicaciones exceden el monto del pago';
        END IF;
    END IF;
END
SQL;
        $t['payment_applications_bi'] = "CREATE TRIGGER payment_applications_bi BEFORE INSERT ON payment_applications\n" . $paymentBody;
        $t['payment_applications_bu'] = "CREATE TRIGGER payment_applications_bu BEFORE UPDATE ON payment_applications\n" . $paymentBody;

        return $t;
    }

    public function up(): void
    {
        foreach ($this->triggers() as $name => $sql) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys($this->triggers())) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }
};

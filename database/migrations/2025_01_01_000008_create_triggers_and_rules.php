<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Reglas que no se pueden expresar de forma declarativa.
 * NOTA: Row Level Security (RLS con current_setting('app.organization_id')) se dejó
 * deliberadamente fuera de esta migración.
 */
return new class extends Migration
{
    private array $updatedAtTables = [
        'organizations',
        'organization_fiscal_settings',
        'branches',
        'users',
        'roles',
        'organization_members',
        'patients',
        'treatments',
        'appointments',
        'inventory_stock',
    ];

    private array $functions = [
        'set_updated_at()',
        'guard_signed_document()',
        'guard_prescription_items()',
        'guard_immutable_inventory_movements()',
        'apply_inventory_movement()',
        'guard_closed_cash_session()',
        'guard_system_admin_role()',
        'guard_last_active_admin()',
        'revoke_sessions_on_member_change()',
        'validate_payment_application()',
    ];

    public function up(): void
    {
        // ---------- updated_at automático ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach ($this->updatedAtTables as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_updated_at BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION set_updated_at()");
        }

        // ---------- documentos firmados inmutables ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_signed_document() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'firmada' THEN
        RAISE EXCEPTION '% firmada(o) es inmutable (id %); use adendas o cree un documento nuevo', TG_TABLE_NAME, OLD.id
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_clinical_notes_signed_guard
    BEFORE UPDATE OR DELETE ON clinical_notes
    FOR EACH ROW EXECUTE FUNCTION guard_signed_document();

CREATE TRIGGER trg_prescriptions_signed_guard
    BEFORE UPDATE OR DELETE ON prescriptions
    FOR EACH ROW EXECUTE FUNCTION guard_signed_document();

CREATE FUNCTION guard_prescription_items() RETURNS trigger AS $$
DECLARE
    v_status signing_status;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        SELECT status INTO v_status FROM prescriptions
         WHERE organization_id = OLD.organization_id AND id = OLD.prescription_id;
        IF v_status = 'firmada' THEN
            RAISE EXCEPTION 'Los conceptos de una receta firmada son inmutables (receta %)', OLD.prescription_id
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        SELECT status INTO v_status FROM prescriptions
         WHERE organization_id = NEW.organization_id AND id = NEW.prescription_id;
        IF v_status = 'firmada' THEN
            RAISE EXCEPTION 'No se pueden agregar/modificar conceptos de una receta firmada (receta %)', NEW.prescription_id
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prescription_items_signed_guard
    BEFORE INSERT OR UPDATE OR DELETE ON prescription_items
    FOR EACH ROW EXECUTE FUNCTION guard_prescription_items();
SQL);

        // ---------- inventario ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_immutable_inventory_movements() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'inventory_movements es inmutable; corrija con un ajuste o una reversión'
        USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_inventory_movements_immutable
    BEFORE UPDATE OR DELETE ON inventory_movements
    FOR EACH ROW EXECUTE FUNCTION guard_immutable_inventory_movements();

CREATE FUNCTION apply_inventory_movement() RETURNS trigger AS $$
BEGIN
    INSERT INTO inventory_stock (organization_id, branch_id, item_id, quantity_on_hand, avg_unit_cost)
    VALUES (
        NEW.organization_id, NEW.branch_id, NEW.item_id, NEW.quantity,
        CASE WHEN NEW.quantity > 0 THEN COALESCE(NEW.unit_cost, 0) ELSE 0 END
    )
    ON CONFLICT (branch_id, item_id) DO UPDATE SET
        avg_unit_cost = CASE
            WHEN NEW.quantity > 0
             AND NEW.unit_cost IS NOT NULL
             AND (inventory_stock.quantity_on_hand + NEW.quantity) > 0
            THEN (inventory_stock.quantity_on_hand * inventory_stock.avg_unit_cost + NEW.quantity * NEW.unit_cost)
                 / (inventory_stock.quantity_on_hand + NEW.quantity)
            ELSE inventory_stock.avg_unit_cost
        END,
        quantity_on_hand = inventory_stock.quantity_on_hand + NEW.quantity,
        updated_at = now();
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_inventory_movements_apply
    AFTER INSERT ON inventory_movements
    FOR EACH ROW EXECUTE FUNCTION apply_inventory_movement();
SQL);

        // ---------- caja cerrada inmutable ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_closed_cash_session() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'cerrada' THEN
        RAISE EXCEPTION 'La sesión de caja % está cerrada; use cash_session_adjustments', OLD.id
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_cash_sessions_closed_guard
    BEFORE UPDATE OR DELETE ON cash_sessions
    FOR EACH ROW EXECUTE FUNCTION guard_closed_cash_session();
SQL);

        // ---------- rol de sistema y último administrador ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_system_admin_role() RETURNS trigger AS $$
BEGIN
    IF OLD.is_system_admin THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'El rol de administrador del sistema no se puede eliminar'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        IF NEW.code IS DISTINCT FROM OLD.code
           OR NEW.status IS DISTINCT FROM OLD.status
           OR NEW.is_system_admin IS DISTINCT FROM OLD.is_system_admin THEN
            RAISE EXCEPTION 'El rol de administrador del sistema no es editable ni desactivable'
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_roles_system_admin_guard
    BEFORE UPDATE OR DELETE ON roles
    FOR EACH ROW EXECUTE FUNCTION guard_system_admin_role();

CREATE FUNCTION guard_last_active_admin() RETURNS trigger AS $$
DECLARE
    v_was_admin boolean;
    v_stays_admin boolean := false;
BEGIN
    SELECT OLD.is_active AND EXISTS (
        SELECT 1 FROM roles r
         WHERE r.organization_id = OLD.organization_id AND r.id = OLD.role_id AND r.is_system_admin
    ) INTO v_was_admin;

    IF v_was_admin THEN
        IF TG_OP = 'UPDATE' THEN
            SELECT NEW.is_active AND EXISTS (
                SELECT 1 FROM roles r
                 WHERE r.organization_id = NEW.organization_id AND r.id = NEW.role_id AND r.is_system_admin
            ) INTO v_stays_admin;
        END IF;

        IF NOT v_stays_admin THEN
            -- serializa cambios de administradores dentro de la organización
            PERFORM 1 FROM organizations WHERE id = OLD.organization_id FOR UPDATE;

            IF NOT EXISTS (
                SELECT 1
                  FROM organization_members m
                  JOIN roles r ON r.organization_id = m.organization_id AND r.id = m.role_id
                 WHERE m.organization_id = OLD.organization_id
                   AND m.id <> OLD.id
                   AND m.is_active
                   AND r.is_system_admin
                   AND r.status = 'active'
            ) THEN
                RAISE EXCEPTION 'La organización debe conservar al menos un administrador activo'
                    USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_members_last_admin_guard
    BEFORE UPDATE OR DELETE ON organization_members
    FOR EACH ROW EXECUTE FUNCTION guard_last_active_admin();

CREATE FUNCTION revoke_sessions_on_member_change() RETURNS trigger AS $$
BEGIN
    UPDATE user_sessions
       SET revoked_at = now(),
           revoked_reason = CASE WHEN NOT NEW.is_active THEN 'deactivated' ELSE 'role_changed' END
     WHERE user_id = NEW.user_id
       AND organization_id = NEW.organization_id
       AND revoked_at IS NULL;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_members_revoke_sessions
    AFTER UPDATE ON organization_members
    FOR EACH ROW
    WHEN (OLD.role_id IS DISTINCT FROM NEW.role_id OR OLD.is_active IS DISTINCT FROM NEW.is_active)
    EXECUTE FUNCTION revoke_sessions_on_member_change();
SQL);

        // ---------- aplicaciones de pago ----------
        DB::unprepared(<<<'SQL'
CREATE FUNCTION validate_payment_application() RETURNS trigger AS $$
DECLARE
    v_invoice_total numeric(14,2);
    v_payment_amount numeric(14,2);
    v_invoice_applied numeric(14,2);
    v_payment_applied numeric(14,2);
BEGIN
    IF NEW.reversed_at IS NOT NULL THEN
        RETURN NEW;
    END IF;

    SELECT total INTO v_invoice_total
      FROM invoices
     WHERE organization_id = NEW.organization_id AND id = NEW.invoice_id
       FOR UPDATE;

    SELECT amount INTO v_payment_amount
      FROM payments
     WHERE organization_id = NEW.organization_id AND id = NEW.payment_id
       FOR UPDATE;

    SELECT COALESCE(SUM(applied_amount), 0) INTO v_invoice_applied
      FROM payment_applications
     WHERE organization_id = NEW.organization_id
       AND invoice_id = NEW.invoice_id
       AND reversed_at IS NULL
       AND id <> NEW.id;

    SELECT COALESCE(SUM(applied_amount), 0) INTO v_payment_applied
      FROM payment_applications
     WHERE organization_id = NEW.organization_id
       AND payment_id = NEW.payment_id
       AND reversed_at IS NULL
       AND id <> NEW.id;

    IF v_invoice_applied + NEW.applied_amount > v_invoice_total THEN
        RAISE EXCEPTION 'Las aplicaciones (%) exceden el total de la factura (%)',
            v_invoice_applied + NEW.applied_amount, v_invoice_total
            USING ERRCODE = 'check_violation';
    END IF;

    IF v_payment_applied + NEW.applied_amount > v_payment_amount THEN
        RAISE EXCEPTION 'Las aplicaciones (%) exceden el monto del pago (%)',
            v_payment_applied + NEW.applied_amount, v_payment_amount
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_payment_applications_validate
    BEFORE INSERT OR UPDATE ON payment_applications
    FOR EACH ROW EXECUTE FUNCTION validate_payment_application();
SQL);
    }

    public function down(): void
    {
        // DROP ... CASCADE elimina también los triggers que dependen de cada función.
        foreach (array_reverse($this->functions) as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function} CASCADE");
        }
    }
};

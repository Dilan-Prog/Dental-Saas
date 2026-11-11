<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ======================= 12. FINANZAS Y CAJA =======================
CREATE TABLE financial_categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(100) NOT NULL,
    direction movement_direction NOT NULL,
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, name, direction)
);

CREATE TABLE cash_registers (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    name varchar(100) NOT NULL,
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, branch_id, name),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id)
);

CREATE TABLE cash_sessions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    cash_register_id uuid NOT NULL,
    opened_by uuid NOT NULL REFERENCES users(id),
    opened_at timestamptz NOT NULL,
    opening_amount numeric(14,2) NOT NULL CHECK (opening_amount >= 0),
    status cash_session_status NOT NULL DEFAULT 'abierta',
    closed_by uuid REFERENCES users(id),
    closed_at timestamptz,
    expected_cash numeric(14,2),
    counted_cash numeric(14,2),
    difference numeric(14,2),
    notes text,
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, cash_register_id) REFERENCES cash_registers (organization_id, id)
);
CREATE UNIQUE INDEX uq_cash_sessions_one_open ON cash_sessions (cash_register_id) WHERE status = 'abierta';

CREATE TABLE cash_session_adjustments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    cash_session_id uuid NOT NULL,
    amount numeric(14,2) NOT NULL,
    reason text NOT NULL,
    created_by uuid NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (organization_id, cash_session_id) REFERENCES cash_sessions (organization_id, id)
);

CREATE TABLE financial_movements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    branch_id uuid,
    direction movement_direction NOT NULL,
    category_id uuid NOT NULL,
    concept varchar(300) NOT NULL,
    occurred_on date NOT NULL,
    amount numeric(14,2) NOT NULL CHECK (amount > 0),
    currency char(3) NOT NULL DEFAULT 'MXN',
    method payment_method NOT NULL,
    payment_id uuid,
    cash_session_id uuid,
    receipt_storage_key text,
    created_by uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    reversed_of_id uuid,
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, category_id) REFERENCES financial_categories (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, payment_id) REFERENCES payments (organization_id, id),
    FOREIGN KEY (organization_id, cash_session_id) REFERENCES cash_sessions (organization_id, id),
    FOREIGN KEY (organization_id, reversed_of_id) REFERENCES financial_movements (organization_id, id)
);
CREATE UNIQUE INDEX uq_fin_mov_payment ON financial_movements (organization_id, payment_id) WHERE payment_id IS NOT NULL;
CREATE INDEX idx_fin_mov_date_dir ON financial_movements (organization_id, occurred_on, direction);
CREATE INDEX idx_fin_mov_category ON financial_movements (organization_id, category_id);

-- ========================= 13. INVENTARIO ==========================
CREATE TABLE suppliers (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(200) NOT NULL,
    contact_phone varchar(30),
    contact_email varchar(254),
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id)
);

CREATE TABLE inventory_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(200) NOT NULL,
    sku varchar(60) NOT NULL,
    category varchar(100),
    unit_of_measure varchar(30) NOT NULL,
    supplier_id uuid,
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, sku),
    FOREIGN KEY (organization_id, supplier_id) REFERENCES suppliers (organization_id, id)
);
CREATE INDEX idx_inventory_items_name ON inventory_items (organization_id, name);

CREATE TABLE inventory_stock (
    organization_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    item_id uuid NOT NULL,
    quantity_on_hand numeric(14,4) NOT NULL DEFAULT 0 CHECK (quantity_on_hand >= 0),
    min_quantity numeric(14,4) NOT NULL DEFAULT 0,
    location varchar(100),
    avg_unit_cost numeric(14,4) NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (branch_id, item_id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, item_id) REFERENCES inventory_items (organization_id, id)
);

CREATE TABLE inventory_lots (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    item_id uuid NOT NULL,
    lot_code varchar(60) NOT NULL,
    expires_on date,
    quantity_on_hand numeric(14,4) NOT NULL DEFAULT 0,
    UNIQUE (organization_id, id),
    UNIQUE (branch_id, item_id, lot_code),
    FOREIGN KEY (organization_id, item_id) REFERENCES inventory_items (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id)
);

CREATE TABLE inventory_movements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    item_id uuid NOT NULL,
    movement_type inventory_movement_type NOT NULL,
    quantity numeric(14,4) NOT NULL CHECK (quantity <> 0),
    unit_cost numeric(14,4),
    reference varchar(120),
    reason text,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    actor_user_id uuid NOT NULL REFERENCES users(id),
    encounter_id uuid,
    lot_id uuid,
    reverses_movement_id uuid,
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, item_id) REFERENCES inventory_items (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, encounter_id) REFERENCES clinical_encounters (organization_id, id),
    FOREIGN KEY (organization_id, lot_id) REFERENCES inventory_lots (organization_id, id),
    FOREIGN KEY (organization_id, reverses_movement_id) REFERENCES inventory_movements (organization_id, id)
);
CREATE INDEX idx_inv_mov_branch_item_date ON inventory_movements (organization_id, branch_id, item_id, occurred_at);
CREATE UNIQUE INDEX uq_inv_mov_initial ON inventory_movements (branch_id, item_id) WHERE movement_type = 'inventario_inicial';

-- =============== 14. NOTIFICACIONES, OUTBOX, DASHBOARD =============
CREATE TABLE notification_preferences (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    event_type varchar(60) NOT NULL,
    channel notification_channel NOT NULL,
    is_enabled boolean NOT NULL DEFAULT true,
    lead_time_minutes integer,
    recipient_role_id uuid,
    UNIQUE (organization_id, event_type, channel),
    FOREIGN KEY (organization_id, recipient_role_id) REFERENCES roles (organization_id, id)
);

CREATE TABLE notifications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    event_type varchar(60) NOT NULL,
    title varchar(200) NOT NULL,
    body text,
    entity_type varchar(60),
    entity_id uuid,
    dedup_key varchar(200) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, dedup_key)
);

CREATE TABLE notification_recipients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    notification_id uuid NOT NULL,
    user_id uuid REFERENCES users(id),
    patient_id uuid,
    channel notification_channel NOT NULL,
    scheduled_for timestamptz NOT NULL,
    read_at timestamptz,
    FOREIGN KEY (organization_id, notification_id) REFERENCES notifications (organization_id, id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id)
);
CREATE INDEX idx_notif_recipients_user_read ON notification_recipients (user_id, read_at);
CREATE INDEX idx_notif_recipients_scheduled ON notification_recipients (scheduled_for);

CREATE TABLE outbox_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    event_type varchar(80) NOT NULL,
    aggregate_type varchar(60) NOT NULL,
    aggregate_id uuid NOT NULL,
    payload jsonb NOT NULL,
    status outbox_status NOT NULL DEFAULT 'pendiente',
    available_at timestamptz NOT NULL DEFAULT now(),
    processed_at timestamptz,
    attempts integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_outbox_status_available ON outbox_events (status, available_at);
CREATE INDEX idx_outbox_aggregate ON outbox_events (organization_id, aggregate_type, aggregate_id);

CREATE TABLE delivery_attempts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    outbox_event_id uuid REFERENCES outbox_events(id),
    recipient_id uuid REFERENCES notification_recipients(id),
    channel notification_channel NOT NULL,
    status delivery_status NOT NULL DEFAULT 'pendiente',
    provider_message_id varchar(200),
    error_detail text,
    attempted_at timestamptz NOT NULL DEFAULT now()
);

-- ------------------ FKs diferidas (dependencias circulares) ------------------
ALTER TABLE payments
    ADD CONSTRAINT payments_cash_session_fk
    FOREIGN KEY (organization_id, cash_session_id) REFERENCES cash_sessions (organization_id, id);

ALTER TABLE prescription_deliveries
    ADD CONSTRAINT prescription_deliveries_outbox_event_fk
    FOREIGN KEY (outbox_event_id) REFERENCES outbox_events (id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE prescription_deliveries DROP CONSTRAINT IF EXISTS prescription_deliveries_outbox_event_fk');
        DB::unprepared('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_cash_session_fk');
        DB::unprepared('DROP TABLE IF EXISTS delivery_attempts, outbox_events, notification_recipients, notifications, notification_preferences, inventory_movements, inventory_lots, inventory_stock, inventory_items, suppliers, financial_movements, cash_session_adjustments, cash_sessions, cash_registers, financial_categories CASCADE');
    }
};

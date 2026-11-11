<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ===================== 10. PRESUPUESTOS =====================
CREATE TABLE quotes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    folio varchar(20) NOT NULL,
    patient_id uuid NOT NULL,
    doctor_member_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    quote_date date NOT NULL,
    status quote_status NOT NULL DEFAULT 'borrador',
    currency char(3) NOT NULL DEFAULT 'MXN',
    subtotal numeric(14,2) NOT NULL DEFAULT 0,
    discount_total numeric(14,2) NOT NULL DEFAULT 0,
    tax_total numeric(14,2) NOT NULL DEFAULT 0,
    total numeric(14,2) NOT NULL DEFAULT 0,
    sent_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, folio),
    CONSTRAINT quotes_total_check CHECK (total = subtotal - discount_total + tax_total),
    FOREIGN KEY (organization_id) REFERENCES organizations (id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, doctor_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id)
);
CREATE INDEX idx_quotes_status_sent ON quotes (organization_id, status, sent_at);

CREATE TABLE quote_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    line_number smallint NOT NULL,
    treatment_id uuid,
    description_snapshot varchar(300) NOT NULL,
    sat_product_key_snapshot varchar(8),
    tooth_fdi smallint,
    quantity numeric(14,4) NOT NULL CHECK (quantity > 0),
    unit_price numeric(14,4) NOT NULL,
    discount_pct numeric(5,2) NOT NULL DEFAULT 0 CHECK (discount_pct >= 0 AND discount_pct <= 100),
    tax_rate_snapshot numeric(7,6) NOT NULL DEFAULT 0,
    tax_is_exempt boolean NOT NULL DEFAULT false,
    line_subtotal numeric(14,2) NOT NULL,
    line_discount numeric(14,2) NOT NULL,
    line_tax numeric(14,2) NOT NULL,
    line_total numeric(14,2) NOT NULL,
    UNIQUE (organization_id, id),
    UNIQUE (quote_id, line_number),
    FOREIGN KEY (organization_id, quote_id) REFERENCES quotes (organization_id, id),
    FOREIGN KEY (organization_id, treatment_id) REFERENCES treatments (organization_id, id)
);

ALTER TABLE patient_treatments
    ADD CONSTRAINT patient_treatments_quote_item_fk
    FOREIGN KEY (organization_id, quote_item_id) REFERENCES quote_items (organization_id, id);

CREATE TABLE quote_status_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    quote_id uuid NOT NULL,
    from_status quote_status,
    to_status quote_status NOT NULL,
    actor_user_id uuid REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (organization_id, quote_id) REFERENCES quotes (organization_id, id)
);

-- ================ 11. FACTURACIÓN Y COBRANZA ================
CREATE TABLE invoices (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    internal_folio varchar(20) NOT NULL,
    series varchar(10),
    fiscal_folio integer,
    uuid_fiscal uuid,
    cfdi_type cfdi_type NOT NULL DEFAULT 'I',
    patient_id uuid NOT NULL,
    doctor_member_id uuid,
    branch_id uuid NOT NULL,
    quote_id uuid,
    issued_at timestamptz,
    stamped_at timestamptz,
    issuer_snapshot jsonb NOT NULL,
    receiver_snapshot jsonb NOT NULL,
    cfdi_use varchar(4) NOT NULL,
    cfdi_payment_method cfdi_payment_method NOT NULL,
    cfdi_payment_form varchar(2) NOT NULL,
    currency char(3) NOT NULL DEFAULT 'MXN',
    exchange_rate numeric(12,6) DEFAULT 1,
    subtotal numeric(14,2) NOT NULL,
    discount_total numeric(14,2) NOT NULL DEFAULT 0,
    tax_total numeric(14,2) NOT NULL DEFAULT 0,
    total numeric(14,2) NOT NULL,
    fiscal_status fiscal_status NOT NULL DEFAULT 'borrador',
    collection_status collection_status NOT NULL DEFAULT 'pendiente',
    xml_storage_key text,
    pdf_storage_key text,
    pac_provider varchar(60),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, internal_folio),
    UNIQUE (organization_id, series, fiscal_folio),
    FOREIGN KEY (organization_id) REFERENCES organizations (id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, doctor_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, quote_id) REFERENCES quotes (organization_id, id)
);
CREATE UNIQUE INDEX uq_invoices_uuid_fiscal ON invoices (organization_id, uuid_fiscal) WHERE uuid_fiscal IS NOT NULL;
CREATE INDEX idx_invoices_patient ON invoices (organization_id, patient_id);
CREATE INDEX idx_invoices_fiscal_status ON invoices (organization_id, fiscal_status, issued_at);
CREATE INDEX idx_invoices_collection_status ON invoices (organization_id, collection_status);

CREATE TABLE invoice_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    line_number smallint NOT NULL,
    treatment_id uuid,
    quote_item_id uuid,
    description varchar(300) NOT NULL,
    sat_product_key varchar(8) NOT NULL,
    sat_unit_key varchar(3) NOT NULL,
    quantity numeric(14,4) NOT NULL,
    unit_price numeric(14,4) NOT NULL,
    discount numeric(14,2) NOT NULL DEFAULT 0,
    amount numeric(14,2) NOT NULL,
    tax_object_code varchar(2) NOT NULL DEFAULT '02',
    UNIQUE (organization_id, id),
    UNIQUE (invoice_id, line_number),
    FOREIGN KEY (organization_id, invoice_id) REFERENCES invoices (organization_id, id),
    FOREIGN KEY (organization_id, treatment_id) REFERENCES treatments (organization_id, id),
    FOREIGN KEY (organization_id, quote_item_id) REFERENCES quote_items (organization_id, id)
);

CREATE TABLE invoice_item_taxes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    invoice_item_id uuid NOT NULL,
    tax_code varchar(3) NOT NULL,
    factor_type varchar(10) NOT NULL,
    rate numeric(7,6),
    base numeric(14,2) NOT NULL,
    amount numeric(14,2),
    is_withholding boolean NOT NULL DEFAULT false,
    FOREIGN KEY (organization_id, invoice_item_id) REFERENCES invoice_items (organization_id, id)
);

CREATE TABLE invoice_relations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    related_invoice_id uuid NOT NULL,
    relation_type_code varchar(2) NOT NULL,
    FOREIGN KEY (organization_id, invoice_id) REFERENCES invoices (organization_id, id),
    FOREIGN KEY (organization_id, related_invoice_id) REFERENCES invoices (organization_id, id)
);

CREATE TABLE pac_requests (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    kind pac_request_kind NOT NULL,
    status pac_request_status NOT NULL DEFAULT 'pendiente',
    idempotency_key varchar(100) NOT NULL,
    attempt_number integer NOT NULL DEFAULT 1,
    request_payload_ref text,
    response_code varchar(40),
    response_message text,
    error_detail text,
    requested_at timestamptz NOT NULL DEFAULT now(),
    responded_at timestamptz,
    next_retry_at timestamptz,
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, idempotency_key),
    FOREIGN KEY (organization_id, invoice_id) REFERENCES invoices (organization_id, id)
);
CREATE INDEX idx_pac_requests_invoice ON pac_requests (organization_id, invoice_id);
CREATE INDEX idx_pac_requests_retry ON pac_requests (status, next_retry_at);

CREATE TABLE invoice_cancellations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    reason_code varchar(2) NOT NULL CHECK (reason_code IN ('01', '02', '03', '04')),
    substitute_invoice_id uuid,
    status cancellation_status NOT NULL DEFAULT 'solicitada',
    requested_by uuid REFERENCES users (id),
    requested_at timestamptz NOT NULL DEFAULT now(),
    response_at timestamptz,
    response_detail text,
    pac_request_id uuid,
    FOREIGN KEY (organization_id, invoice_id) REFERENCES invoices (organization_id, id),
    FOREIGN KEY (organization_id, substitute_invoice_id) REFERENCES invoices (organization_id, id),
    FOREIGN KEY (organization_id, pac_request_id) REFERENCES pac_requests (organization_id, id)
);

CREATE TABLE payments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    paid_at timestamptz NOT NULL,
    amount numeric(14,2) NOT NULL CHECK (amount > 0),
    currency char(3) NOT NULL DEFAULT 'MXN',
    method payment_method NOT NULL,
    reference varchar(120),
    status payment_status NOT NULL DEFAULT 'registrado',
    idempotency_key varchar(100) NOT NULL,
    cash_session_id uuid,
    created_by uuid REFERENCES users (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, idempotency_key),
    FOREIGN KEY (organization_id) REFERENCES organizations (id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id)
);
CREATE UNIQUE INDEX uq_payments_transfer_reference ON payments (organization_id, reference)
    WHERE reference IS NOT NULL AND method = 'transferencia';
CREATE INDEX idx_payments_method_reference ON payments (organization_id, method, reference);
CREATE INDEX idx_payments_paid_at ON payments (organization_id, paid_at);

CREATE TABLE payment_applications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    applied_amount numeric(14,2) NOT NULL CHECK (applied_amount > 0),
    applied_at timestamptz NOT NULL DEFAULT now(),
    reversed_at timestamptz,
    FOREIGN KEY (organization_id, payment_id) REFERENCES payments (organization_id, id),
    FOREIGN KEY (organization_id, invoice_id) REFERENCES invoices (organization_id, id)
);
CREATE INDEX idx_payment_applications_invoice ON payment_applications (organization_id, invoice_id);
CREATE INDEX idx_payment_applications_payment ON payment_applications (organization_id, payment_id);

CREATE TABLE payment_reversals (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    amount numeric(14,2) NOT NULL,
    reason text NOT NULL,
    reversed_by uuid REFERENCES users (id),
    reversed_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (organization_id, payment_id) REFERENCES payments (organization_id, id)
);

CREATE TABLE payment_complements (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    complement_invoice_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    related_invoice_id uuid NOT NULL,
    installment_number integer,
    previous_balance numeric(14,2),
    paid_amount numeric(14,2),
    remaining_balance numeric(14,2),
    FOREIGN KEY (organization_id, complement_invoice_id) REFERENCES invoices (organization_id, id),
    FOREIGN KEY (organization_id, payment_id) REFERENCES payments (organization_id, id),
    FOREIGN KEY (organization_id, related_invoice_id) REFERENCES invoices (organization_id, id)
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE IF EXISTS patient_treatments DROP CONSTRAINT IF EXISTS patient_treatments_quote_item_fk');
        DB::unprepared('DROP TABLE IF EXISTS payment_complements, payment_reversals, payment_applications, payments, invoice_cancellations, pac_requests, invoice_relations, invoice_item_taxes, invoice_items, invoices, quote_status_history, quote_items, quotes CASCADE');
    }
};

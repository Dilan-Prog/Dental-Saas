<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE patients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    folio varchar(20) NOT NULL,
    full_name varchar(250) NOT NULL,
    birth_date date,
    sex sex_type NOT NULL DEFAULT 'unspecified',
    phone varchar(30),
    email varchar(254),
    assigned_doctor_member_id uuid,
    status record_status NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    anonymized_at timestamptz,
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, folio),
    FOREIGN KEY (organization_id, assigned_doctor_member_id) REFERENCES organization_members (organization_id, id)
);
CREATE INDEX idx_patients_name_trgm ON patients USING gin (full_name gin_trgm_ops);
CREATE INDEX idx_patients_org_name ON patients (organization_id, full_name);
CREATE INDEX idx_patients_org_phone ON patients (organization_id, phone);
CREATE INDEX idx_patients_org_email ON patients (organization_id, email);

CREATE TABLE patient_fiscal_profiles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    rfc varchar(13) NOT NULL CHECK (rfc ~ '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$'),
    legal_name varchar(300) NOT NULL,
    tax_regime_code varchar(3) NOT NULL,
    fiscal_postal_code varchar(5) NOT NULL,
    default_cfdi_use varchar(4),
    is_default boolean NOT NULL DEFAULT true,
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id)
);

CREATE TABLE patient_guardians (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    full_name varchar(250) NOT NULL,
    relationship varchar(60),
    phone varchar(30),
    email varchar(254),
    is_primary boolean NOT NULL DEFAULT true,
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id)
);

CREATE TABLE tax_profiles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(100) NOT NULL,
    tax_code varchar(3) NOT NULL DEFAULT '002',
    rate numeric(7,6) NOT NULL CHECK (rate >= 0),
    is_exempt boolean NOT NULL DEFAULT false,
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, name)
);

CREATE TABLE treatment_categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(100) NOT NULL,
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, name)
);

CREATE TABLE treatments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    category_id uuid,
    name varchar(200) NOT NULL,
    duration_minutes integer NOT NULL CHECK (duration_minutes > 0),
    sat_product_key varchar(8),
    sat_unit_key varchar(3) DEFAULT 'E48',
    price numeric(14,4) NOT NULL CHECK (price >= 0),
    currency char(3) NOT NULL DEFAULT 'MXN',
    tax_profile_id uuid,
    status record_status NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, category_id) REFERENCES treatment_categories (organization_id, id),
    FOREIGN KEY (organization_id, tax_profile_id) REFERENCES tax_profiles (organization_id, id)
);
CREATE INDEX idx_treatments_org_name ON treatments (organization_id, name);

CREATE TABLE appointments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    branch_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    doctor_member_id uuid NOT NULL,
    treatment_id uuid,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    status appointment_status NOT NULL DEFAULT 'pendiente',
    notes text,
    created_by uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    CHECK (ends_at > starts_at),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, doctor_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, treatment_id) REFERENCES treatments (organization_id, id),
    CONSTRAINT appointments_no_doctor_overlap EXCLUDE USING gist (
        doctor_member_id WITH =, tstzrange(starts_at, ends_at) WITH &&
    ) WHERE (status IN ('pendiente', 'confirmada', 'en_curso')),
    CONSTRAINT appointments_no_patient_overlap EXCLUDE USING gist (
        patient_id WITH =, tstzrange(starts_at, ends_at) WITH &&
    ) WHERE (status IN ('pendiente', 'confirmada', 'en_curso'))
);
CREATE INDEX idx_appt_branch_start ON appointments (organization_id, branch_id, starts_at);
CREATE INDEX idx_appt_doctor_start ON appointments (organization_id, doctor_member_id, starts_at);
CREATE INDEX idx_appt_patient_start ON appointments (organization_id, patient_id, starts_at);
CREATE INDEX idx_appt_status_start ON appointments (organization_id, status, starts_at);

CREATE TABLE appointment_status_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    appointment_id uuid NOT NULL,
    from_status appointment_status,
    to_status appointment_status NOT NULL,
    old_starts_at timestamptz,
    new_starts_at timestamptz,
    reason text,
    actor_user_id uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id)
);
CREATE INDEX idx_appt_hist ON appointment_status_history (appointment_id, created_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS appointment_status_history, appointments, treatments, treatment_categories, tax_profiles, patient_guardians, patient_fiscal_profiles, patients CASCADE');
    }
};

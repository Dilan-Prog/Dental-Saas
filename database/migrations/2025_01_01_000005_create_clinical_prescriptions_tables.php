<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ===================== 6. EXPEDIENTE CLÍNICO =====================
CREATE TABLE medical_records (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    allergies text,
    medical_history text,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, patient_id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id)
);

CREATE TABLE clinical_encounters (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    medical_record_id uuid NOT NULL,
    appointment_id uuid,
    branch_id uuid NOT NULL,
    doctor_member_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, medical_record_id) REFERENCES medical_records (organization_id, id),
    FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id),
    FOREIGN KEY (organization_id, doctor_member_id) REFERENCES organization_members (organization_id, id)
);
CREATE INDEX idx_encounters_record_occurred ON clinical_encounters (organization_id, medical_record_id, occurred_at);

-- quote_item_id: la FK a quote_items se agrega en la migración de presupuestos.
CREATE TABLE patient_treatments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    treatment_id uuid NOT NULL,
    quote_item_id uuid,
    tooth_fdi smallint,
    status planned_treatment_status NOT NULL DEFAULT 'planeado',
    started_at timestamptz,
    completed_at timestamptz,
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, treatment_id) REFERENCES treatments (organization_id, id)
);

CREATE TABLE clinical_notes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    encounter_id uuid NOT NULL,
    note_type note_type NOT NULL DEFAULT 'simple',
    author_member_id uuid NOT NULL,
    responsible_doctor_member_id uuid NOT NULL,
    treatment_id uuid,
    tooth_fdi smallint,
    appointment_id uuid,
    subjective text,
    objective text,
    analysis text,
    plan text,
    prescription_text text,
    status signing_status NOT NULL DEFAULT 'borrador',
    signed_at timestamptz,
    signed_by_member_id uuid,
    content_hash char(64),
    signature_mechanism varchar(40),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    CHECK (status = 'borrador' OR (signed_at IS NOT NULL AND signed_by_member_id IS NOT NULL AND content_hash IS NOT NULL)),
    FOREIGN KEY (organization_id, encounter_id) REFERENCES clinical_encounters (organization_id, id),
    FOREIGN KEY (organization_id, author_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, responsible_doctor_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, signed_by_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, treatment_id) REFERENCES treatments (organization_id, id),
    FOREIGN KEY (organization_id, appointment_id) REFERENCES appointments (organization_id, id)
);
CREATE INDEX idx_clinical_notes_encounter ON clinical_notes (organization_id, encounter_id);

CREATE TABLE clinical_note_addenda (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    note_id uuid NOT NULL,
    version_number integer NOT NULL,
    content text NOT NULL,
    reason text NOT NULL,
    author_member_id uuid NOT NULL,
    content_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (note_id, version_number),
    FOREIGN KEY (organization_id, note_id) REFERENCES clinical_notes (organization_id, id),
    FOREIGN KEY (organization_id, author_member_id) REFERENCES organization_members (organization_id, id)
);

-- ======================= 7. ODONTOGRAMA ==========================
CREATE TABLE tooth_findings (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    tooth_fdi smallint NOT NULL,
    dentition dentition_type NOT NULL,
    condition tooth_condition NOT NULL,
    surfaces varchar(10)[],
    encounter_id uuid,
    patient_treatment_id uuid,
    author_member_id uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT now(),
    superseded_at timestamptz,
    CONSTRAINT tooth_findings_fdi_check CHECK (
        (dentition = 'permanente' AND (
            tooth_fdi BETWEEN 11 AND 18 OR tooth_fdi BETWEEN 21 AND 28
            OR tooth_fdi BETWEEN 31 AND 38 OR tooth_fdi BETWEEN 41 AND 48))
        OR
        (dentition = 'temporal' AND (
            tooth_fdi BETWEEN 51 AND 55 OR tooth_fdi BETWEEN 61 AND 65
            OR tooth_fdi BETWEEN 71 AND 75 OR tooth_fdi BETWEEN 81 AND 85))
    ),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, encounter_id) REFERENCES clinical_encounters (organization_id, id),
    FOREIGN KEY (organization_id, patient_treatment_id) REFERENCES patient_treatments (organization_id, id),
    FOREIGN KEY (organization_id, author_member_id) REFERENCES organization_members (organization_id, id)
);
CREATE INDEX idx_tooth_findings_history ON tooth_findings (organization_id, patient_id, tooth_fdi, recorded_at);
CREATE UNIQUE INDEX uq_tooth_findings_current ON tooth_findings (organization_id, patient_id, tooth_fdi) WHERE superseded_at IS NULL;

-- ===================== 8. ARCHIVOS CLÍNICOS ======================
CREATE TABLE clinical_files (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    file_type clinical_file_type NOT NULL,
    display_name varchar(255) NOT NULL,
    mime_type varchar(100) NOT NULL,
    size_bytes bigint NOT NULL CHECK (size_bytes > 0),
    storage_key text NOT NULL,
    sha256 char(64),
    uploaded_by_member_id uuid NOT NULL,
    encounter_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, storage_key),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, uploaded_by_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, encounter_id) REFERENCES clinical_encounters (organization_id, id)
);
CREATE INDEX idx_clinical_files_patient_type ON clinical_files (organization_id, patient_id, file_type);

CREATE TABLE consent_acceptances (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    patient_id uuid NOT NULL,
    clinical_file_id uuid,
    accepted_at timestamptz NOT NULL,
    accepted_by_name varchar(250) NOT NULL,
    method varchar(40) NOT NULL,
    witness_member_id uuid,
    content_hash char(64),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, clinical_file_id) REFERENCES clinical_files (organization_id, id),
    FOREIGN KEY (organization_id, witness_member_id) REFERENCES organization_members (organization_id, id)
);

-- ================= 9. RECETAS Y MEDICAMENTOS =====================
CREATE TABLE medications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid REFERENCES organizations(id),
    name varchar(200) NOT NULL,
    active_ingredient varchar(200),
    strength varchar(80),
    presentation varchar(120),
    status record_status NOT NULL DEFAULT 'active',
    UNIQUE (organization_id, id)
);

CREATE TABLE prescriptions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    folio varchar(20) NOT NULL,
    patient_id uuid NOT NULL,
    doctor_member_id uuid NOT NULL,
    issued_on date NOT NULL,
    diagnosis text,
    general_instructions text,
    status signing_status NOT NULL DEFAULT 'borrador',
    signed_at timestamptz,
    doctor_name_snapshot varchar(200),
    doctor_license_snapshot varchar(30),
    content_hash char(64),
    pdf_storage_key text,
    repeated_from_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, folio),
    FOREIGN KEY (organization_id, patient_id) REFERENCES patients (organization_id, id),
    FOREIGN KEY (organization_id, doctor_member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, repeated_from_id) REFERENCES prescriptions (organization_id, id)
);
CREATE INDEX idx_prescriptions_patient_issued ON prescriptions (organization_id, patient_id, issued_on);

CREATE TABLE prescription_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    prescription_id uuid NOT NULL,
    line_number smallint NOT NULL,
    medication_id uuid,
    medication_name varchar(200) NOT NULL,
    strength varchar(80),
    presentation varchar(120),
    dose varchar(120) NOT NULL,
    route varchar(60),
    frequency varchar(120) NOT NULL,
    duration varchar(120),
    instructions text,
    UNIQUE (prescription_id, line_number),
    FOREIGN KEY (organization_id, prescription_id) REFERENCES prescriptions (organization_id, id),
    FOREIGN KEY (organization_id, medication_id) REFERENCES medications (organization_id, id)
);

-- outbox_event_id: la FK a outbox_events se agrega en la migración de notificaciones.
CREATE TABLE prescription_deliveries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL,
    prescription_id uuid NOT NULL,
    channel varchar(20) NOT NULL,
    sent_at timestamptz,
    actor_user_id uuid REFERENCES users(id),
    outbox_event_id uuid,
    FOREIGN KEY (organization_id, prescription_id) REFERENCES prescriptions (organization_id, id)
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS prescription_deliveries, prescription_items, prescriptions, medications, consent_acceptances, clinical_files, tooth_findings, clinical_note_addenda, clinical_notes, patient_treatments, clinical_encounters, medical_records CASCADE');
    }
};

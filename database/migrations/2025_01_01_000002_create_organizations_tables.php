<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE organizations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    commercial_name varchar(200) NOT NULL,
    legal_name varchar(300),
    rfc varchar(13) CHECK (rfc IS NULL OR rfc ~ '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$'),
    phone varchar(30),
    email varchar(254),
    address_line varchar(300),
    address_postal_code varchar(5),
    logo_storage_key text,
    currency char(3) NOT NULL DEFAULT 'MXN',
    timezone varchar(60) NOT NULL DEFAULT 'America/Mexico_City',
    status record_status NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE organization_fiscal_settings (
    organization_id uuid PRIMARY KEY REFERENCES organizations(id),
    tax_regime_code varchar(3),
    fiscal_postal_code varchar(5),
    pac_provider varchar(60),
    default_series varchar(10),
    csd_certificate_secret_ref text,
    csd_key_secret_ref text,
    pac_credentials_secret_ref text,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE folio_sequences (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    document_type varchar(20) NOT NULL,
    series varchar(10) NOT NULL DEFAULT '',
    prefix varchar(10) NOT NULL,
    last_value bigint NOT NULL DEFAULT 0,
    UNIQUE (organization_id, document_type, series)
);

CREATE TABLE branches (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    name varchar(150) NOT NULL,
    address_line varchar(300),
    phone varchar(30),
    manager_user_id uuid,
    status branch_status NOT NULL DEFAULT 'active',
    is_main boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, name)
);
CREATE UNIQUE INDEX uq_one_main_branch ON branches (organization_id) WHERE is_main AND status = 'active';

-- Siguiente folio atómico: PAC-0001, RX-0001, ...
CREATE FUNCTION next_folio(p_org uuid, p_type varchar, p_series varchar DEFAULT '') RETURNS text AS $$
DECLARE
    v_prefix varchar;
    v_val bigint;
BEGIN
    UPDATE folio_sequences
       SET last_value = last_value + 1
     WHERE organization_id = p_org AND document_type = p_type AND series = p_series
 RETURNING prefix, last_value INTO v_prefix, v_val;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'folio_sequences no configurada para % / % / %', p_org, p_type, p_series;
    END IF;
    RETURN v_prefix || lpad(v_val::text, 4, '0');
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS next_folio(uuid, varchar, varchar)');
        DB::unprepared('DROP TABLE IF EXISTS branches, folio_sequences, organization_fiscal_settings, organizations CASCADE');
    }
};

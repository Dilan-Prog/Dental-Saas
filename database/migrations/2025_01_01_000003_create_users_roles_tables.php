<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    auth_provider_id varchar(200) UNIQUE,
    full_name varchar(200) NOT NULL,
    email citext NOT NULL UNIQUE,
    avatar_storage_key text,
    is_active boolean NOT NULL DEFAULT true,
    last_login_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE roles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    code varchar(50) NOT NULL,
    name varchar(100) NOT NULL,
    description text,
    is_system_admin boolean NOT NULL DEFAULT false,
    status record_status NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, id),
    UNIQUE (organization_id, code),
    UNIQUE (organization_id, name)
);

CREATE TABLE permissions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    module varchar(50) NOT NULL,
    action varchar(50) NOT NULL,
    description text,
    UNIQUE (module, action)
);

CREATE TABLE role_permissions (
    organization_id uuid NOT NULL,
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL REFERENCES permissions(id),
    granted_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id)
);

CREATE TABLE organization_members (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    user_id uuid NOT NULL REFERENCES users(id),
    role_id uuid,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (organization_id, user_id),
    UNIQUE (organization_id, id),
    FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id)
);

CREATE TABLE member_branches (
    organization_id uuid NOT NULL,
    member_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    PRIMARY KEY (member_id, branch_id),
    FOREIGN KEY (organization_id, member_id) REFERENCES organization_members (organization_id, id),
    FOREIGN KEY (organization_id, branch_id) REFERENCES branches (organization_id, id)
);

CREATE TABLE doctor_profiles (
    member_id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    professional_license varchar(30),
    specialty varchar(120),
    signature_image_storage_key text,
    FOREIGN KEY (organization_id, member_id) REFERENCES organization_members (organization_id, id)
);

CREATE TABLE user_invitations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    email varchar(254) NOT NULL,
    role_id uuid,
    invited_by_member_id uuid,
    token_hash text NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    accepted_at timestamptz,
    revoked_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id),
    FOREIGN KEY (organization_id, invited_by_member_id) REFERENCES organization_members (organization_id, id)
);

CREATE TABLE user_sessions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id),
    organization_id uuid REFERENCES organizations(id),
    auth_session_id varchar(200),
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    revoked_at timestamptz,
    revoked_reason varchar(60)
);
CREATE INDEX idx_user_sessions_user_revoked ON user_sessions (user_id, revoked_at);

CREATE TABLE role_change_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    change_type varchar(40) NOT NULL,
    target_member_id uuid,
    role_id uuid,
    old_value jsonb,
    new_value jsonb,
    actor_user_id uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_role_change_history_org_created ON role_change_history (organization_id, created_at);

CREATE TABLE audit_log (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id uuid NOT NULL REFERENCES organizations(id),
    actor_user_id uuid REFERENCES users(id),
    entity_type varchar(60) NOT NULL,
    entity_id uuid NOT NULL,
    action varchar(20) NOT NULL,
    changed_fields jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_log_entity ON audit_log (organization_id, entity_type, entity_id);
CREATE INDEX idx_audit_log_org_created ON audit_log (organization_id, created_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_log, role_change_history, user_sessions, user_invitations, doctor_profiles, member_branches, organization_members, role_permissions, permissions, roles, users CASCADE');
    }
};

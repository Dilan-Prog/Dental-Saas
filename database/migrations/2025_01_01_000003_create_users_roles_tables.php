<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('auth_provider_id', 200)->nullable();
            $table->string('full_name', 200);
            $table->string('email', 254);
            $table->text('avatar_storage_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('auth_provider_id', 'uq_users_auth_provider');
            $table->unique('email', 'uq_users_email');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_system_admin')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'id'], 'uq_roles_org_id');
            $table->unique(['organization_id', 'code'], 'uq_roles_org_code');
            $table->unique(['organization_id', 'name'], 'uq_roles_org_name');
            $table->foreign('organization_id', 'fk_roles_org')->references('id')->on('organizations');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('module', 50);
            $table->string('action', 50);
            $table->text('description')->nullable();

            $table->unique(['module', 'action'], 'uq_permissions_module_action');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->dateTime('granted_at')->useCurrent();

            $table->primary(['role_id', 'permission_id']);
            $table->foreign(['organization_id', 'role_id'], 'fk_rp_role')->references(['organization_id', 'id'])->on('roles');
            $table->foreign('permission_id', 'fk_rp_permission')->references('id')->on('permissions');
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('role_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'user_id'], 'uq_members_org_user');
            $table->unique(['organization_id', 'id'], 'uq_members_org_id');
            $table->foreign('organization_id', 'fk_members_org')->references('id')->on('organizations');
            $table->foreign('user_id', 'fk_members_user')->references('id')->on('users');
            $table->foreign(['organization_id', 'role_id'], 'fk_members_role')->references(['organization_id', 'id'])->on('roles');
        });

        Schema::create('member_branches', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('member_id');
            $table->uuid('branch_id');

            $table->primary(['member_id', 'branch_id']);
            $table->foreign(['organization_id', 'member_id'], 'fk_mb_member')->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'branch_id'], 'fk_mb_branch')->references(['organization_id', 'id'])->on('branches');
        });

        Schema::create('doctor_profiles', function (Blueprint $table) {
            $table->uuid('member_id')->primary();
            $table->uuid('organization_id');
            $table->string('professional_license', 30)->nullable();
            $table->string('specialty', 120)->nullable();
            $table->text('signature_image_storage_key')->nullable();

            $table->foreign(['organization_id', 'member_id'], 'fk_doctor_member')->references(['organization_id', 'id'])->on('organization_members');
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('email', 254);
            $table->uuid('role_id')->nullable();
            $table->uuid('invited_by_member_id')->nullable();
            $table->string('token_hash', 255);
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique('token_hash', 'uq_invitations_token_hash');
            $table->foreign('organization_id', 'fk_inv_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'role_id'], 'fk_inv_role')->references(['organization_id', 'id'])->on('roles');
            $table->foreign(['organization_id', 'invited_by_member_id'], 'fk_inv_inviter')->references(['organization_id', 'id'])->on('organization_members');
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('user_id');
            $table->uuid('organization_id')->nullable();
            $table->string('auth_session_id', 200)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 60)->nullable();

            $table->index(['user_id', 'revoked_at'], 'idx_user_sessions_user_revoked');
            $table->foreign('user_id', 'fk_sessions_user')->references('id')->on('users');
            $table->foreign('organization_id', 'fk_sessions_org')->references('id')->on('organizations');
        });

        Schema::create('role_change_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('change_type', 40);
            $table->uuid('target_member_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'idx_rch_org_created');
            $table->foreign('organization_id', 'fk_rch_org')->references('id')->on('organizations');
            $table->foreign('actor_user_id', 'fk_rch_actor')->references('id')->on('users');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('actor_user_id')->nullable();
            $table->string('entity_type', 60);
            $table->uuid('entity_id');
            $table->string('action', 20);
            $table->json('changed_fields')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index(['organization_id', 'created_at'], 'idx_audit_org_created');
            $table->foreign('organization_id', 'fk_audit_org')->references('id')->on('organizations');
            $table->foreign('actor_user_id', 'fk_audit_actor')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'audit_log', 'role_change_history', 'user_sessions', 'user_invitations',
            'doctor_profiles', 'member_branches', 'organization_members',
            'role_permissions', 'permissions', 'roles', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
    }
};

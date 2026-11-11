<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->string('commercial_name', 200);
            $table->string('legal_name', 300)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address_line', 300)->nullable();
            $table->string('address_postal_code', 5)->nullable();
            $table->text('logo_storage_key')->nullable();
            $table->char('currency', 3)->default('MXN');
            $table->string('timezone', 60)->default('America/Mexico_City');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT chk_org_rfc CHECK (rfc IS NULL OR REGEXP_LIKE(rfc, '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$', 'c'))");

        Schema::create('organization_fiscal_settings', function (Blueprint $table) {
            $table->uuid('organization_id')->primary();
            $table->string('tax_regime_code', 3)->nullable();
            $table->string('fiscal_postal_code', 5)->nullable();
            $table->string('pac_provider', 60)->nullable();
            $table->string('default_series', 10)->nullable();
            $table->text('csd_certificate_secret_ref')->nullable();
            $table->text('csd_key_secret_ref')->nullable();
            $table->text('pac_credentials_secret_ref')->nullable();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('organization_id', 'fk_ofs_org')->references('id')->on('organizations');
        });

        Schema::create('folio_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('document_type', 20);
            $table->string('series', 10)->default('');
            $table->string('prefix', 10);
            $table->unsignedBigInteger('last_value')->default(0);

            $table->unique(['organization_id', 'document_type', 'series'], 'uq_folio_seq_org_type_series');
            $table->foreign('organization_id', 'fk_folio_seq_org')->references('id')->on('organizations');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 150);
            $table->string('address_line', 300)->nullable();
            $table->string('phone', 30)->nullable();
            $table->uuid('manager_user_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_main')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'id'], 'uq_branches_org_id');
            $table->unique(['organization_id', 'name'], 'uq_branches_org_name');
            $table->foreign('organization_id', 'fk_branches_org')->references('id')->on('organizations');
        });
        // Índice único PARCIAL: una sola sucursal principal activa por organización.
        DB::statement("ALTER TABLE branches ADD COLUMN main_org_key CHAR(36) GENERATED ALWAYS AS (IF(is_main = 1 AND status = 'active', organization_id, NULL)) VIRTUAL, ADD UNIQUE KEY uq_one_main_branch (main_org_key)");

        DB::unprepared('DROP PROCEDURE IF EXISTS next_folio');

        // Siguiente folio atómico: PAC-0001, RX-0001, ...
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE next_folio(IN p_org CHAR(36), IN p_type VARCHAR(20), IN p_series VARCHAR(10), OUT p_folio VARCHAR(40))
BEGIN
    DECLARE v_prefix VARCHAR(10);
    UPDATE folio_sequences
       SET `last_value` = LAST_INSERT_ID(`last_value` + 1)
     WHERE organization_id = p_org AND document_type = p_type AND series = p_series;
    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folio_sequences no configurada';
    END IF;
    SELECT prefix INTO v_prefix FROM folio_sequences
     WHERE organization_id = p_org AND document_type = p_type AND series = p_series;
    SET p_folio = CONCAT(v_prefix, LPAD(LAST_INSERT_ID(), 4, '0'));
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS next_folio');
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('branches');
        Schema::dropIfExists('folio_sequences');
        Schema::dropIfExists('organization_fiscal_settings');
        Schema::dropIfExists('organizations');
        Schema::enableForeignKeyConstraints();
    }
};

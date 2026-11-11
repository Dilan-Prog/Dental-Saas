<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ======================= 12. FINANZAS Y CAJA =======================
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->enum('direction', ['ingreso', 'egreso']);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_fin_categories_org_id');
            $table->unique(['organization_id', 'name', 'direction'], 'uq_fin_categories_org_name_dir');
            $table->foreign('organization_id', 'fk_fincat_org')->references('id')->on('organizations');
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name', 100);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_cash_registers_org_id');
            $table->unique(['organization_id', 'branch_id', 'name'], 'uq_cash_registers_org_branch_name');
            $table->foreign(['organization_id', 'branch_id'], 'fk_cashreg_branch')
                ->references(['organization_id', 'id'])->on('branches');
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('cash_register_id');
            $table->uuid('opened_by');
            $table->dateTime('opened_at');
            $table->decimal('opening_amount', 14, 2);
            $table->enum('status', ['abierta', 'cerrada'])->default('abierta');
            $table->uuid('closed_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->text('notes')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_cash_sessions_org_id');
            $table->foreign(['organization_id', 'cash_register_id'], 'fk_cashsess_register')
                ->references(['organization_id', 'id'])->on('cash_registers');
            $table->foreign('opened_by', 'fk_cashsess_opened_by')->references('id')->on('users');
            $table->foreign('closed_by', 'fk_cashsess_closed_by')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE cash_sessions ADD CONSTRAINT chk_cash_sessions_opening CHECK (opening_amount >= 0)');
        // Índice único parcial: una sola sesión abierta por caja.
        DB::statement("ALTER TABLE cash_sessions
            ADD COLUMN open_register_key CHAR(36) GENERATED ALWAYS AS (IF(status = 'abierta', cash_register_id, NULL)) VIRTUAL,
            ADD UNIQUE KEY uq_cash_one_open (open_register_key)");

        Schema::create('cash_session_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('cash_session_id');
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->uuid('created_by');
            $table->dateTime('created_at')->useCurrent();

            $table->foreign(['organization_id', 'cash_session_id'], 'fk_cashadj_session')
                ->references(['organization_id', 'id'])->on('cash_sessions');
            $table->foreign('created_by', 'fk_cashadj_created_by')->references('id')->on('users');
        });

        Schema::create('financial_movements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id')->nullable();
            $table->enum('direction', ['ingreso', 'egreso']);
            $table->uuid('category_id');
            $table->string('concept', 300);
            $table->date('occurred_on');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('MXN');
            $table->enum('method', ['efectivo', 'tarjeta', 'transferencia', 'domiciliado']);
            $table->uuid('payment_id')->nullable();
            $table->uuid('cash_session_id')->nullable();
            $table->text('receipt_storage_key')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->uuid('reversed_of_id')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_fin_movements_org_id');
            // Un pago = un movimiento (MySQL permite múltiples NULL en unique).
            $table->unique(['organization_id', 'payment_id'], 'uq_fin_movements_org_payment');
            $table->index(['organization_id', 'occurred_on', 'direction'], 'idx_fin_mov_date_dir');
            $table->index(['organization_id', 'category_id'], 'idx_fin_mov_category');
            $table->foreign(['organization_id', 'category_id'], 'fk_finmov_category')
                ->references(['organization_id', 'id'])->on('financial_categories');
            $table->foreign(['organization_id', 'branch_id'], 'fk_finmov_branch')
                ->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'payment_id'], 'fk_finmov_payment')
                ->references(['organization_id', 'id'])->on('payments');
            $table->foreign(['organization_id', 'cash_session_id'], 'fk_finmov_cash_session')
                ->references(['organization_id', 'id'])->on('cash_sessions');
            $table->foreign(['organization_id', 'reversed_of_id'], 'fk_finmov_reversed_of')
                ->references(['organization_id', 'id'])->on('financial_movements');
            $table->foreign('created_by', 'fk_finmov_created_by')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE financial_movements ADD CONSTRAINT chk_fin_movements_amount CHECK (amount > 0)');

        // ========================= 13. INVENTARIO ==========================
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 200);
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_suppliers_org_id');
            $table->foreign('organization_id', 'fk_suppliers_org')->references('id')->on('organizations');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 200);
            $table->string('sku', 60);
            $table->string('category', 100)->nullable();
            $table->string('unit_of_measure', 30);
            $table->uuid('supplier_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_inventory_items_org_id');
            $table->unique(['organization_id', 'sku'], 'uq_inventory_items_org_sku');
            $table->index(['organization_id', 'name'], 'idx_inventory_items_org_name');
            $table->foreign('organization_id', 'fk_invitems_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'supplier_id'], 'fk_invitems_supplier')
                ->references(['organization_id', 'id'])->on('suppliers');
        });

        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('item_id');
            $table->decimal('quantity_on_hand', 14, 4)->default(0);
            $table->decimal('min_quantity', 14, 4)->default(0);
            $table->string('location', 100)->nullable();
            $table->decimal('avg_unit_cost', 14, 4)->default(0);
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['branch_id', 'item_id'], 'pk_inventory_stock');
            $table->foreign(['organization_id', 'branch_id'], 'fk_invstock_branch')
                ->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'item_id'], 'fk_invstock_item')
                ->references(['organization_id', 'id'])->on('inventory_items');
        });
        DB::statement('ALTER TABLE inventory_stock ADD CONSTRAINT chk_inventory_stock_qty CHECK (quantity_on_hand >= 0)');

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('item_id');
            $table->string('lot_code', 60);
            $table->date('expires_on')->nullable();
            $table->decimal('quantity_on_hand', 14, 4)->default(0);

            $table->unique(['organization_id', 'id'], 'uq_inventory_lots_org_id');
            $table->unique(['branch_id', 'item_id', 'lot_code'], 'uq_inventory_lots_branch_item_lot');
            $table->foreign(['organization_id', 'item_id'], 'fk_invlots_item')
                ->references(['organization_id', 'id'])->on('inventory_items');
            $table->foreign(['organization_id', 'branch_id'], 'fk_invlots_branch')
                ->references(['organization_id', 'id'])->on('branches');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('item_id');
            $table->enum('movement_type', ['inventario_inicial', 'entrada', 'salida', 'ajuste', 'reversion']);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('occurred_at')->useCurrent();
            $table->uuid('actor_user_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('lot_id')->nullable();
            $table->uuid('reverses_movement_id')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_inventory_movements_org_id');
            $table->index(['organization_id', 'branch_id', 'item_id', 'occurred_at'], 'idx_inv_mov_branch_item_date');
            $table->foreign(['organization_id', 'item_id'], 'fk_invmov_item')
                ->references(['organization_id', 'id'])->on('inventory_items');
            $table->foreign(['organization_id', 'branch_id'], 'fk_invmov_branch')
                ->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'encounter_id'], 'fk_invmov_encounter')
                ->references(['organization_id', 'id'])->on('clinical_encounters');
            $table->foreign(['organization_id', 'lot_id'], 'fk_invmov_lot')
                ->references(['organization_id', 'id'])->on('inventory_lots');
            $table->foreign(['organization_id', 'reverses_movement_id'], 'fk_invmov_reverses')
                ->references(['organization_id', 'id'])->on('inventory_movements');
            $table->foreign('actor_user_id', 'fk_invmov_actor')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_inventory_movements_qty CHECK (quantity <> 0)');
        // Índice único parcial: un solo inventario_inicial por sucursal/artículo.
        DB::statement("ALTER TABLE inventory_movements
            ADD COLUMN initial_stock_key CHAR(73) GENERATED ALWAYS AS (IF(movement_type = 'inventario_inicial', CONCAT(branch_id, ':', item_id), NULL)) VIRTUAL,
            ADD UNIQUE KEY uq_inv_mov_initial (initial_stock_key)");

        // =============== 14. NOTIFICACIONES, OUTBOX, DASHBOARD =============
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('event_type', 60);
            $table->enum('channel', ['in_app', 'email', 'whatsapp']);
            $table->boolean('is_enabled')->default(true);
            $table->integer('lead_time_minutes')->nullable();
            $table->uuid('recipient_role_id')->nullable();

            $table->unique(['organization_id', 'event_type', 'channel'], 'uq_notif_pref_org_event_channel');
            $table->foreign('organization_id', 'fk_notifpref_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'recipient_role_id'], 'fk_notifpref_role')
                ->references(['organization_id', 'id'])->on('roles');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('event_type', 60);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('dedup_key', 200);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_notifications_org_id');
            $table->unique(['organization_id', 'dedup_key'], 'uq_notifications_org_dedup');
            $table->foreign('organization_id', 'fk_notif_org')->references('id')->on('organizations');
        });

        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('notification_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('patient_id')->nullable();
            $table->enum('channel', ['in_app', 'email', 'whatsapp']);
            $table->dateTime('scheduled_for');
            $table->dateTime('read_at')->nullable();

            $table->index(['user_id', 'read_at'], 'idx_notif_recip_user_read');
            $table->index('scheduled_for', 'idx_notif_recip_scheduled');
            $table->foreign(['organization_id', 'notification_id'], 'fk_notifrecip_notif')
                ->references(['organization_id', 'id'])->on('notifications');
            $table->foreign(['organization_id', 'patient_id'], 'fk_notifrecip_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign('user_id', 'fk_notifrecip_user')->references('id')->on('users');
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('event_type', 80);
            $table->string('aggregate_type', 60);
            $table->uuid('aggregate_id');
            $table->json('payload');
            $table->enum('status', ['pendiente', 'procesando', 'enviado', 'fallido'])->default('pendiente');
            $table->dateTime('available_at')->useCurrent();
            $table->dateTime('processed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['status', 'available_at'], 'idx_outbox_status_available');
            $table->index(['organization_id', 'aggregate_type', 'aggregate_id'], 'idx_outbox_aggregate');
            $table->foreign('organization_id', 'fk_outbox_org')->references('id')->on('organizations');
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('outbox_event_id')->nullable();
            $table->uuid('recipient_id')->nullable();
            $table->enum('channel', ['in_app', 'email', 'whatsapp']);
            $table->enum('status', ['pendiente', 'enviado', 'entregado', 'fallido'])->default('pendiente');
            $table->string('provider_message_id', 200)->nullable();
            $table->text('error_detail')->nullable();
            $table->dateTime('attempted_at')->useCurrent();

            $table->foreign('outbox_event_id', 'fk_delivery_outbox')->references('id')->on('outbox_events');
            $table->foreign('recipient_id', 'fk_delivery_recipient')->references('id')->on('notification_recipients');
        });

        // ------------- FKs diferidas (dependencias circulares) -------------
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign(['organization_id', 'cash_session_id'], 'fk_pay_cash_session')
                ->references(['organization_id', 'id'])->on('cash_sessions');
        });

        Schema::table('prescription_deliveries', function (Blueprint $table) {
            $table->foreign('outbox_event_id', 'fk_rxdeliv_outbox')->references('id')->on('outbox_events');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('prescription_deliveries', function (Blueprint $table) {
            $table->dropForeign('fk_rxdeliv_outbox');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('fk_pay_cash_session');
        });

        foreach ([
            'delivery_attempts', 'outbox_events', 'notification_recipients', 'notifications',
            'notification_preferences', 'inventory_movements', 'inventory_lots', 'inventory_stock',
            'inventory_items', 'suppliers', 'financial_movements', 'cash_session_adjustments',
            'cash_sessions', 'cash_registers', 'financial_categories',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::enableForeignKeyConstraints();
    }
};

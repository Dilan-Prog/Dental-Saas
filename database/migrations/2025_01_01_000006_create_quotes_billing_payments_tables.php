<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===================== 10. PRESUPUESTOS =====================
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('folio', 20);
            $table->uuid('patient_id');
            $table->uuid('doctor_member_id');
            $table->uuid('branch_id');
            $table->date('quote_date');
            $table->enum('status', ['borrador', 'enviado', 'aprobado', 'rechazado'])->default('borrador');
            $table->char('currency', 3)->default('MXN');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_quotes_org_id');
            $table->unique(['organization_id', 'folio'], 'uq_quotes_org_folio');
            $table->index(['organization_id', 'status', 'sent_at'], 'idx_quotes_status_sent');
            $table->foreign('organization_id', 'fk_quotes_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'patient_id'], 'fk_quotes_patient')->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'doctor_member_id'], 'fk_quotes_doctor')->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'branch_id'], 'fk_quotes_branch')->references(['organization_id', 'id'])->on('branches');
        });
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT chk_quotes_total CHECK (total = subtotal - discount_total + tax_total)');

        Schema::create('quote_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('quote_id');
            $table->smallInteger('line_number');
            $table->uuid('treatment_id')->nullable();
            $table->string('description_snapshot', 300);
            $table->string('sat_product_key_snapshot', 8)->nullable();
            $table->smallInteger('tooth_fdi')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('tax_rate_snapshot', 7, 6)->default(0);
            $table->boolean('tax_is_exempt')->default(false);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_discount', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('line_total', 14, 2);

            $table->unique(['organization_id', 'id'], 'uq_quote_items_org_id');
            $table->unique(['quote_id', 'line_number'], 'uq_quote_items_line');
            $table->foreign(['organization_id', 'quote_id'], 'fk_qitems_quote')->references(['organization_id', 'id'])->on('quotes');
            $table->foreign(['organization_id', 'treatment_id'], 'fk_qitems_treatment')->references(['organization_id', 'id'])->on('treatments');
        });
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT chk_quote_items_qty CHECK (quantity > 0)');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT chk_quote_items_disc CHECK (discount_pct >= 0 AND discount_pct <= 100)');

        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->foreign(['organization_id', 'quote_item_id'], 'fk_pt_quote_item')->references(['organization_id', 'id'])->on('quote_items');
        });

        Schema::create('quote_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('quote_id');
            $table->enum('from_status', ['borrador', 'enviado', 'aprobado', 'rechazado'])->nullable();
            $table->enum('to_status', ['borrador', 'enviado', 'aprobado', 'rechazado']);
            $table->uuid('actor_user_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign(['organization_id', 'quote_id'], 'fk_qhist_quote')->references(['organization_id', 'id'])->on('quotes');
            $table->foreign('actor_user_id', 'fk_qhist_actor')->references('id')->on('users');
        });

        // ================ 11. FACTURACIÓN Y COBRANZA ================
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('internal_folio', 20);
            $table->string('series', 10)->nullable();
            $table->integer('fiscal_folio')->nullable();
            $table->uuid('uuid_fiscal')->nullable();
            $table->enum('cfdi_type', ['I', 'E', 'P'])->default('I');
            $table->uuid('patient_id');
            $table->uuid('doctor_member_id')->nullable();
            $table->uuid('branch_id');
            $table->uuid('quote_id')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('stamped_at')->nullable();
            $table->json('issuer_snapshot');
            $table->json('receiver_snapshot');
            $table->string('cfdi_use', 4);
            $table->enum('cfdi_payment_method', ['PUE', 'PPD']);
            $table->string('cfdi_payment_form', 2);
            $table->char('currency', 3)->default('MXN');
            $table->decimal('exchange_rate', 12, 6)->nullable()->default(1);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->enum('fiscal_status', ['borrador', 'pendiente_timbrado', 'timbrada', 'cancelacion_solicitada', 'cancelada', 'error'])->default('borrador');
            $table->enum('collection_status', ['pendiente', 'pago_parcial', 'pagada'])->default('pendiente');
            $table->text('xml_storage_key')->nullable();
            $table->text('pdf_storage_key')->nullable();
            $table->string('pac_provider', 60)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_invoices_org_id');
            $table->unique(['organization_id', 'internal_folio'], 'uq_invoices_org_folio');
            $table->unique(['organization_id', 'uuid_fiscal'], 'uq_invoices_org_uuid_fiscal');
            $table->unique(['organization_id', 'series', 'fiscal_folio'], 'uq_invoices_org_series_folio');
            $table->index(['organization_id', 'patient_id'], 'idx_invoices_patient');
            $table->index(['organization_id', 'fiscal_status', 'issued_at'], 'idx_invoices_fiscal_status');
            $table->index(['organization_id', 'collection_status'], 'idx_invoices_collection');
            $table->foreign('organization_id', 'fk_invoices_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'patient_id'], 'fk_invoices_patient')->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'doctor_member_id'], 'fk_invoices_doctor')->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'branch_id'], 'fk_invoices_branch')->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'quote_id'], 'fk_invoices_quote')->references(['organization_id', 'id'])->on('quotes');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('invoice_id');
            $table->smallInteger('line_number');
            $table->uuid('treatment_id')->nullable();
            $table->uuid('quote_item_id')->nullable();
            $table->string('description', 300);
            $table->string('sat_product_key', 8);
            $table->string('sat_unit_key', 3);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('tax_object_code', 2)->default('02');

            $table->unique(['organization_id', 'id'], 'uq_invoice_items_org_id');
            $table->unique(['invoice_id', 'line_number'], 'uq_invoice_items_line');
            $table->foreign(['organization_id', 'invoice_id'], 'fk_iitems_invoice')->references(['organization_id', 'id'])->on('invoices');
            $table->foreign(['organization_id', 'treatment_id'], 'fk_iitems_treatment')->references(['organization_id', 'id'])->on('treatments');
            $table->foreign(['organization_id', 'quote_item_id'], 'fk_iitems_quote_item')->references(['organization_id', 'id'])->on('quote_items');
        });

        Schema::create('invoice_item_taxes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('invoice_item_id');
            $table->string('tax_code', 3);
            $table->string('factor_type', 10);
            $table->decimal('rate', 7, 6)->nullable();
            $table->decimal('base', 14, 2);
            $table->decimal('amount', 14, 2)->nullable();
            $table->boolean('is_withholding')->default(false);

            $table->foreign(['organization_id', 'invoice_item_id'], 'fk_iitaxes_item')->references(['organization_id', 'id'])->on('invoice_items');
        });

        Schema::create('invoice_relations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('invoice_id');
            $table->uuid('related_invoice_id');
            $table->string('relation_type_code', 2);

            $table->foreign(['organization_id', 'invoice_id'], 'fk_irel_invoice')->references(['organization_id', 'id'])->on('invoices');
            $table->foreign(['organization_id', 'related_invoice_id'], 'fk_irel_related')->references(['organization_id', 'id'])->on('invoices');
        });

        Schema::create('pac_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('invoice_id');
            $table->enum('kind', ['timbrado', 'cancelacion', 'consulta_estatus']);
            $table->enum('status', ['pendiente', 'enviado', 'exitoso', 'error', 'reintento'])->default('pendiente');
            $table->string('idempotency_key', 100);
            $table->integer('attempt_number')->default(1);
            $table->text('request_payload_ref')->nullable();
            $table->string('response_code', 40)->nullable();
            $table->text('response_message')->nullable();
            $table->text('error_detail')->nullable();
            $table->dateTime('requested_at')->useCurrent();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_pac_requests_org_id');
            $table->unique(['organization_id', 'idempotency_key'], 'uq_pac_requests_idem');
            $table->index(['organization_id', 'invoice_id'], 'idx_pac_requests_invoice');
            $table->index(['status', 'next_retry_at'], 'idx_pac_requests_retry');
            $table->foreign(['organization_id', 'invoice_id'], 'fk_pacreq_invoice')->references(['organization_id', 'id'])->on('invoices');
        });

        Schema::create('invoice_cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('invoice_id');
            $table->string('reason_code', 2);
            $table->uuid('substitute_invoice_id')->nullable();
            $table->enum('status', ['solicitada', 'aceptada', 'rechazada', 'en_proceso'])->default('solicitada');
            $table->uuid('requested_by')->nullable();
            $table->dateTime('requested_at')->useCurrent();
            $table->dateTime('response_at')->nullable();
            $table->text('response_detail')->nullable();
            $table->uuid('pac_request_id')->nullable();

            $table->foreign(['organization_id', 'invoice_id'], 'fk_icanc_invoice')->references(['organization_id', 'id'])->on('invoices');
            $table->foreign(['organization_id', 'substitute_invoice_id'], 'fk_icanc_substitute')->references(['organization_id', 'id'])->on('invoices');
            $table->foreign(['organization_id', 'pac_request_id'], 'fk_icanc_pacreq')->references(['organization_id', 'id'])->on('pac_requests');
            $table->foreign('requested_by', 'fk_icanc_user')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE invoice_cancellations ADD CONSTRAINT chk_icanc_reason CHECK (reason_code IN ('01','02','03','04'))");

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('patient_id');
            $table->dateTime('paid_at');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('MXN');
            $table->enum('method', ['efectivo', 'tarjeta', 'transferencia', 'domiciliado']);
            $table->string('reference', 120)->nullable();
            $table->enum('status', ['registrado', 'revertido'])->default('registrado');
            $table->string('idempotency_key', 100);
            $table->uuid('cash_session_id')->nullable(); // FK la añade la migración 07
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_payments_org_id');
            $table->unique(['organization_id', 'idempotency_key'], 'uq_payments_idem');
            $table->index(['organization_id', 'method', 'reference'], 'idx_payments_method_ref');
            $table->index(['organization_id', 'paid_at'], 'idx_payments_paid_at');
            $table->foreign('organization_id', 'fk_payments_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'patient_id'], 'fk_payments_patient')->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'branch_id'], 'fk_payments_branch')->references(['organization_id', 'id'])->on('branches');
            $table->foreign('created_by', 'fk_payments_user')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
        // Único parcial: una referencia de transferencia por organización.
        DB::statement("ALTER TABLE payments
            ADD COLUMN transfer_ref_key VARCHAR(200) GENERATED ALWAYS AS (IF(method = 'transferencia' AND reference IS NOT NULL, CONCAT(organization_id, ':', reference), NULL)) VIRTUAL,
            ADD UNIQUE KEY uq_payments_transfer_ref (transfer_ref_key)");

        Schema::create('payment_applications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->decimal('applied_amount', 14, 2);
            $table->dateTime('applied_at')->useCurrent();
            $table->dateTime('reversed_at')->nullable();

            $table->index(['organization_id', 'invoice_id'], 'idx_payapp_invoice');
            $table->index(['organization_id', 'payment_id'], 'idx_payapp_payment');
            $table->foreign(['organization_id', 'payment_id'], 'fk_payapp_payment')->references(['organization_id', 'id'])->on('payments');
            $table->foreign(['organization_id', 'invoice_id'], 'fk_payapp_invoice')->references(['organization_id', 'id'])->on('invoices');
        });
        DB::statement('ALTER TABLE payment_applications ADD CONSTRAINT chk_payapp_amount CHECK (applied_amount > 0)');

        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('payment_id');
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->uuid('reversed_by')->nullable();
            $table->dateTime('reversed_at')->useCurrent();

            $table->foreign(['organization_id', 'payment_id'], 'fk_payrev_payment')->references(['organization_id', 'id'])->on('payments');
            $table->foreign('reversed_by', 'fk_payrev_user')->references('id')->on('users');
        });

        Schema::create('payment_complements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('complement_invoice_id');
            $table->uuid('payment_id');
            $table->uuid('related_invoice_id');
            $table->integer('installment_number')->nullable();
            $table->decimal('previous_balance', 14, 2)->nullable();
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->decimal('remaining_balance', 14, 2)->nullable();

            $table->foreign(['organization_id', 'complement_invoice_id'], 'fk_paycomp_complement')->references(['organization_id', 'id'])->on('invoices');
            $table->foreign(['organization_id', 'payment_id'], 'fk_paycomp_payment')->references(['organization_id', 'id'])->on('payments');
            $table->foreign(['organization_id', 'related_invoice_id'], 'fk_paycomp_related')->references(['organization_id', 'id'])->on('invoices');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropForeign('fk_pt_quote_item');
        });

        foreach ([
            'payment_complements', 'payment_reversals', 'payment_applications', 'payments',
            'invoice_cancellations', 'pac_requests', 'invoice_relations', 'invoice_item_taxes',
            'invoice_items', 'invoices', 'quote_status_history', 'quote_items', 'quotes',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::enableForeignKeyConstraints();
    }
};

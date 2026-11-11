<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===================== 6. EXPEDIENTE CLÍNICO =====================
        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->text('allergies')->nullable();
            $table->text('medical_history')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_medical_records_org_id');
            $table->unique(['organization_id', 'patient_id'], 'uq_medical_records_org_patient');
            $table->foreign(['organization_id', 'patient_id'], 'fk_mr_patient')
                ->references(['organization_id', 'id'])->on('patients');
        });

        Schema::create('clinical_encounters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('medical_record_id');
            $table->uuid('appointment_id')->nullable();
            $table->uuid('branch_id');
            $table->uuid('doctor_member_id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_clinical_encounters_org_id');
            $table->index(['organization_id', 'medical_record_id', 'occurred_at'], 'idx_enc_record_occurred');
            $table->foreign(['organization_id', 'medical_record_id'], 'fk_enc_record')
                ->references(['organization_id', 'id'])->on('medical_records');
            $table->foreign(['organization_id', 'appointment_id'], 'fk_enc_appt')
                ->references(['organization_id', 'id'])->on('appointments');
            $table->foreign(['organization_id', 'branch_id'], 'fk_enc_branch')
                ->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'doctor_member_id'], 'fk_enc_doctor')
                ->references(['organization_id', 'id'])->on('organization_members');
        });

        // quote_item_id: la FK a quote_items se agrega en la migración de presupuestos.
        Schema::create('patient_treatments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->uuid('treatment_id');
            $table->uuid('quote_item_id')->nullable();
            $table->smallInteger('tooth_fdi')->nullable();
            $table->enum('status', ['planeado', 'en_progreso', 'realizado', 'cancelado'])->default('planeado');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_patient_treatments_org_id');
            $table->foreign(['organization_id', 'patient_id'], 'fk_pt_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'treatment_id'], 'fk_pt_treatment')
                ->references(['organization_id', 'id'])->on('treatments');
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('encounter_id');
            $table->enum('note_type', ['simple', 'soap'])->default('simple');
            $table->uuid('author_member_id');
            $table->uuid('responsible_doctor_member_id');
            $table->uuid('treatment_id')->nullable();
            $table->smallInteger('tooth_fdi')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('analysis')->nullable();
            $table->text('plan')->nullable();
            $table->text('prescription_text')->nullable();
            $table->enum('status', ['borrador', 'firmada'])->default('borrador');
            $table->dateTime('signed_at')->nullable();
            $table->uuid('signed_by_member_id')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('signature_mechanism', 40)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_clinical_notes_org_id');
            $table->index(['organization_id', 'encounter_id'], 'idx_cn_encounter');
            $table->foreign(['organization_id', 'encounter_id'], 'fk_cn_encounter')
                ->references(['organization_id', 'id'])->on('clinical_encounters');
            $table->foreign(['organization_id', 'author_member_id'], 'fk_cn_author')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'responsible_doctor_member_id'], 'fk_cn_responsible')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'signed_by_member_id'], 'fk_cn_signed_by')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'treatment_id'], 'fk_cn_treatment')
                ->references(['organization_id', 'id'])->on('treatments');
            $table->foreign(['organization_id', 'appointment_id'], 'fk_cn_appt')
                ->references(['organization_id', 'id'])->on('appointments');
        });
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT chk_cn_signed_fields CHECK (status = 'borrador' OR (signed_at IS NOT NULL AND signed_by_member_id IS NOT NULL AND content_hash IS NOT NULL))");

        Schema::create('clinical_note_addenda', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('note_id');
            $table->integer('version_number');
            $table->text('content');
            $table->text('reason');
            $table->uuid('author_member_id');
            $table->char('content_hash', 64);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['note_id', 'version_number'], 'uq_cna_note_version');
            $table->foreign(['organization_id', 'note_id'], 'fk_cna_note')
                ->references(['organization_id', 'id'])->on('clinical_notes');
            $table->foreign(['organization_id', 'author_member_id'], 'fk_cna_author')
                ->references(['organization_id', 'id'])->on('organization_members');
        });

        // ======================= 7. ODONTOGRAMA ==========================
        Schema::create('tooth_findings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->smallInteger('tooth_fdi');
            $table->enum('dentition', ['permanente', 'temporal']);
            $table->enum('condition', ['sano', 'caries', 'endodoncia', 'restauracion', 'ausente']);
            $table->json('surfaces')->nullable();
            $table->uuid('encounter_id')->nullable();
            $table->uuid('patient_treatment_id')->nullable();
            $table->uuid('author_member_id');
            $table->dateTime('recorded_at')->useCurrent();
            $table->dateTime('superseded_at')->nullable();

            $table->index(['organization_id', 'patient_id', 'tooth_fdi', 'recorded_at'], 'idx_tf_history');
            $table->foreign(['organization_id', 'patient_id'], 'fk_tf_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'encounter_id'], 'fk_tf_encounter')
                ->references(['organization_id', 'id'])->on('clinical_encounters');
            $table->foreign(['organization_id', 'patient_treatment_id'], 'fk_tf_pt')
                ->references(['organization_id', 'id'])->on('patient_treatments');
            $table->foreign(['organization_id', 'author_member_id'], 'fk_tf_author')
                ->references(['organization_id', 'id'])->on('organization_members');
        });
        DB::statement("ALTER TABLE tooth_findings ADD CONSTRAINT chk_tf_fdi CHECK (
            (dentition = 'permanente' AND (
                tooth_fdi BETWEEN 11 AND 18 OR tooth_fdi BETWEEN 21 AND 28
                OR tooth_fdi BETWEEN 31 AND 38 OR tooth_fdi BETWEEN 41 AND 48))
            OR
            (dentition = 'temporal' AND (
                tooth_fdi BETWEEN 51 AND 55 OR tooth_fdi BETWEEN 61 AND 65
                OR tooth_fdi BETWEEN 71 AND 75 OR tooth_fdi BETWEEN 81 AND 85))
        )");
        // Único parcial (estado actual): columna generada NULL cuando el hallazgo fue reemplazado.
        DB::statement("ALTER TABLE tooth_findings
            ADD COLUMN current_key VARCHAR(80) GENERATED ALWAYS AS (
                IF(superseded_at IS NULL, CONCAT(organization_id, ':', patient_id, ':', tooth_fdi), NULL)
            ) VIRTUAL,
            ADD UNIQUE KEY uq_tooth_current (current_key)");

        // ===================== 8. ARCHIVOS CLÍNICOS ======================
        Schema::create('clinical_files', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->enum('file_type', ['radiografia', 'consentimiento', 'fotografia_intraoral', 'otro']);
            $table->string('display_name', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->string('storage_key', 500);
            $table->char('sha256', 64)->nullable();
            $table->uuid('uploaded_by_member_id');
            $table->uuid('encounter_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('deleted_at')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_clinical_files_org_id');
            $table->unique(['organization_id', 'storage_key'], 'uq_cf_org_storage_key');
            $table->index(['organization_id', 'patient_id', 'file_type'], 'idx_cf_patient_type');
            $table->foreign(['organization_id', 'patient_id'], 'fk_cf_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'uploaded_by_member_id'], 'fk_cf_uploader')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'encounter_id'], 'fk_cf_encounter')
                ->references(['organization_id', 'id'])->on('clinical_encounters');
        });
        DB::statement('ALTER TABLE clinical_files ADD CONSTRAINT chk_cf_size CHECK (size_bytes > 0)');

        Schema::create('consent_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->uuid('clinical_file_id')->nullable();
            $table->dateTime('accepted_at');
            $table->string('accepted_by_name', 250);
            $table->string('method', 40);
            $table->uuid('witness_member_id')->nullable();
            $table->char('content_hash', 64)->nullable();

            $table->foreign(['organization_id', 'patient_id'], 'fk_ca_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'clinical_file_id'], 'fk_ca_file')
                ->references(['organization_id', 'id'])->on('clinical_files');
            $table->foreign(['organization_id', 'witness_member_id'], 'fk_ca_witness')
                ->references(['organization_id', 'id'])->on('organization_members');
        });

        // ================= 9. RECETAS Y MEDICAMENTOS =====================
        Schema::create('medications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id')->nullable();
            $table->string('name', 200);
            $table->string('active_ingredient', 200)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('presentation', 120)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_medications_org_id');
            $table->foreign('organization_id', 'fk_med_org')->references('id')->on('organizations');
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('folio', 20);
            $table->uuid('patient_id');
            $table->uuid('doctor_member_id');
            $table->date('issued_on');
            $table->text('diagnosis')->nullable();
            $table->text('general_instructions')->nullable();
            $table->enum('status', ['borrador', 'firmada'])->default('borrador');
            $table->dateTime('signed_at')->nullable();
            $table->string('doctor_name_snapshot', 200)->nullable();
            $table->string('doctor_license_snapshot', 30)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->text('pdf_storage_key')->nullable();
            $table->uuid('repeated_from_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'uq_prescriptions_org_id');
            $table->unique(['organization_id', 'folio'], 'uq_prescriptions_org_folio');
            $table->index(['organization_id', 'patient_id', 'issued_on'], 'idx_rx_patient_issued');
            $table->foreign(['organization_id', 'patient_id'], 'fk_rx_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'doctor_member_id'], 'fk_rx_doctor')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'repeated_from_id'], 'fk_rx_repeated')
                ->references(['organization_id', 'id'])->on('prescriptions');
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('prescription_id');
            $table->smallInteger('line_number');
            $table->uuid('medication_id')->nullable();
            $table->string('medication_name', 200);
            $table->string('strength', 80)->nullable();
            $table->string('presentation', 120)->nullable();
            $table->string('dose', 120);
            $table->string('route', 60)->nullable();
            $table->string('frequency', 120);
            $table->string('duration', 120)->nullable();
            $table->text('instructions')->nullable();

            $table->unique(['prescription_id', 'line_number'], 'uq_rxi_rx_line');
            $table->foreign(['organization_id', 'prescription_id'], 'fk_rxi_rx')
                ->references(['organization_id', 'id'])->on('prescriptions');
            $table->foreign(['organization_id', 'medication_id'], 'fk_rxi_med')
                ->references(['organization_id', 'id'])->on('medications');
        });

        // outbox_event_id: la FK a outbox_events se agrega en la migración de notificaciones.
        Schema::create('prescription_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('prescription_id');
            $table->string('channel', 20);
            $table->dateTime('sent_at')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('outbox_event_id')->nullable();

            $table->foreign(['organization_id', 'prescription_id'], 'fk_rxd_rx')
                ->references(['organization_id', 'id'])->on('prescriptions');
            $table->foreign('actor_user_id', 'fk_rxd_user')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'prescription_deliveries', 'prescription_items', 'prescriptions', 'medications',
            'consent_acceptances', 'clinical_files', 'tooth_findings', 'clinical_note_addenda',
            'clinical_notes', 'patient_treatments', 'clinical_encounters', 'medical_records',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
    }
};

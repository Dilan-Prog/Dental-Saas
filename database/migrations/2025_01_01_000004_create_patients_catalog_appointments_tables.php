<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RFC_REGEX = '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$';

    public function up(): void
    {
        // ---------------------------- 3. PACIENTES ----------------------------
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('folio', 20);
            $table->string('full_name', 250);
            $table->date('birth_date')->nullable();
            $table->enum('sex', ['female', 'male', 'other', 'unspecified'])->default('unspecified');
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->uuid('assigned_doctor_member_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('anonymized_at')->nullable();

            $table->unique(['organization_id', 'id'], 'uq_patients_org_id');
            $table->unique(['organization_id', 'folio'], 'uq_patients_org_folio');
            $table->index(['organization_id', 'full_name'], 'idx_patients_name');
            $table->index(['organization_id', 'phone'], 'idx_patients_phone');
            $table->index(['organization_id', 'email'], 'idx_patients_email');

            $table->foreign('organization_id', 'fk_patients_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'assigned_doctor_member_id'], 'fk_patients_doctor')
                ->references(['organization_id', 'id'])->on('organization_members');
        });

        Schema::create('patient_fiscal_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->string('rfc', 13);
            $table->string('legal_name', 300);
            $table->string('tax_regime_code', 3);
            $table->string('fiscal_postal_code', 5);
            $table->string('default_cfdi_use', 4)->nullable();
            $table->boolean('is_default')->default(true);

            $table->unique(['organization_id', 'id'], 'uq_pfp_org_id');

            $table->foreign(['organization_id', 'patient_id'], 'fk_pfp_patient')
                ->references(['organization_id', 'id'])->on('patients');
        });
        DB::statement("ALTER TABLE patient_fiscal_profiles ADD CONSTRAINT chk_pfp_rfc CHECK (rfc REGEXP '" . self::RFC_REGEX . "')");

        Schema::create('patient_guardians', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('patient_id');
            $table->string('full_name', 250);
            $table->string('relationship', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->boolean('is_primary')->default(true);

            $table->foreign(['organization_id', 'patient_id'], 'fk_pguard_patient')
                ->references(['organization_id', 'id'])->on('patients');
        });

        // ------------------- 4. CATÁLOGO DE TRATAMIENTOS ----------------------
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->string('tax_code', 3)->default('002');
            $table->decimal('rate', 7, 6);
            $table->boolean('is_exempt')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unique(['organization_id', 'id'], 'uq_taxprof_org_id');
            $table->unique(['organization_id', 'name'], 'uq_taxprof_org_name');

            $table->foreign('organization_id', 'fk_taxprof_org')->references('id')->on('organizations');
        });
        DB::statement('ALTER TABLE tax_profiles ADD CONSTRAINT chk_taxprof_rate CHECK (rate >= 0)');

        Schema::create('treatment_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->string('name', 100);

            $table->unique(['organization_id', 'id'], 'uq_trcat_org_id');
            $table->unique(['organization_id', 'name'], 'uq_trcat_org_name');

            $table->foreign('organization_id', 'fk_trcat_org')->references('id')->on('organizations');
        });

        Schema::create('treatments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('category_id')->nullable();
            $table->string('name', 200);
            $table->integer('duration_minutes');
            $table->string('sat_product_key', 8)->nullable();
            $table->string('sat_unit_key', 3)->nullable()->default('E48');
            $table->decimal('price', 14, 4);
            $table->char('currency', 3)->default('MXN');
            $table->uuid('tax_profile_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'id'], 'uq_treatments_org_id');
            $table->index(['organization_id', 'name'], 'idx_treatments_name');

            $table->foreign('organization_id', 'fk_treatments_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'category_id'], 'fk_treatments_cat')
                ->references(['organization_id', 'id'])->on('treatment_categories');
            $table->foreign(['organization_id', 'tax_profile_id'], 'fk_treatments_tax')
                ->references(['organization_id', 'id'])->on('tax_profiles');
        });
        DB::statement('ALTER TABLE treatments ADD CONSTRAINT chk_treatments_duration CHECK (duration_minutes > 0)');
        DB::statement('ALTER TABLE treatments ADD CONSTRAINT chk_treatments_price CHECK (price >= 0)');

        // ------------------------ 5. AGENDA Y CITAS ---------------------------
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('patient_id');
            $table->uuid('doctor_member_id');
            $table->uuid('treatment_id')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio'])
                ->default('pendiente');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['organization_id', 'id'], 'uq_appt_org_id');
            $table->index(['organization_id', 'branch_id', 'starts_at'], 'idx_appt_branch_start');
            $table->index(['organization_id', 'doctor_member_id', 'starts_at'], 'idx_appt_doctor_start');
            $table->index(['organization_id', 'patient_id', 'starts_at'], 'idx_appt_patient_start');
            $table->index(['organization_id', 'status', 'starts_at'], 'idx_appt_status_start');

            $table->foreign('organization_id', 'fk_appt_org')->references('id')->on('organizations');
            $table->foreign(['organization_id', 'branch_id'], 'fk_appt_branch')
                ->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'patient_id'], 'fk_appt_patient')
                ->references(['organization_id', 'id'])->on('patients');
            $table->foreign(['organization_id', 'doctor_member_id'], 'fk_appt_doctor')
                ->references(['organization_id', 'id'])->on('organization_members');
            $table->foreign(['organization_id', 'treatment_id'], 'fk_appt_treatment')
                ->references(['organization_id', 'id'])->on('treatments');
            $table->foreign('created_by', 'fk_appt_created_by')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT chk_appt_time CHECK (ends_at > starts_at)');

        // Anti-empalme (sustituye EXCLUDE gist): doctor y paciente, solo citas activas.
        $overlapBody = <<<'SQL'
BEGIN
    IF NEW.status IN ('pendiente', 'confirmada', 'en_curso') THEN
        IF EXISTS (
            SELECT 1 FROM appointments a
             WHERE a.doctor_member_id = NEW.doctor_member_id
               AND a.id <> NEW.id
               AND a.status IN ('pendiente', 'confirmada', 'en_curso')
               AND a.starts_at < NEW.ends_at AND a.ends_at > NEW.starts_at
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointments_no_doctor_overlap: el doctor ya tiene una cita en ese horario';
        END IF;
        IF EXISTS (
            SELECT 1 FROM appointments a
             WHERE a.patient_id = NEW.patient_id
               AND a.id <> NEW.id
               AND a.status IN ('pendiente', 'confirmada', 'en_curso')
               AND a.starts_at < NEW.ends_at AND a.ends_at > NEW.starts_at
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'appointments_no_patient_overlap: el paciente ya tiene una cita en ese horario';
        END IF;
    END IF;
END
SQL;
        DB::unprepared('CREATE TRIGGER trg_appointments_overlap_bi BEFORE INSERT ON appointments FOR EACH ROW ' . $overlapBody);
        DB::unprepared('CREATE TRIGGER trg_appointments_overlap_bu BEFORE UPDATE ON appointments FOR EACH ROW ' . $overlapBody);

        Schema::create('appointment_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            $table->uuid('organization_id');
            $table->uuid('appointment_id');
            $table->enum('from_status', ['pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio'])->nullable();
            $table->enum('to_status', ['pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio']);
            $table->dateTime('old_starts_at')->nullable();
            $table->dateTime('new_starts_at')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at'], 'idx_appt_hist');

            $table->foreign(['organization_id', 'appointment_id'], 'fk_appt_hist_appt')
                ->references(['organization_id', 'id'])->on('appointments');
            $table->foreign('actor_user_id', 'fk_appt_hist_actor')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::unprepared('DROP TRIGGER IF EXISTS trg_appointments_overlap_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_appointments_overlap_bu');
        foreach ([
            'appointment_status_history', 'appointments', 'treatments', 'treatment_categories',
            'tax_profiles', 'patient_guardians', 'patient_fiscal_profiles', 'patients',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
    }
};

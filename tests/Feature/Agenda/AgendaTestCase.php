<?php

namespace Tests\Feature\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Treatment;
use App\Support\Folio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Utilidades comunes de las pruebas del módulo Agenda. */
abstract class AgendaTestCase extends TestCase
{
    protected string $doctorMemberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctorMemberId = $this->memberId($this->userByEmail('doctor@dental.test'));
    }

    protected function makePatient(string $name = 'Roberto Mendoza Lira', array $extra = []): Patient
    {
        $patient = Patient::create(array_merge([
            'folio' => Folio::next(Folio::PATIENT),
            'full_name' => $name,
            'phone' => '5512345678',
        ], $extra));
        MedicalRecord::query()->firstOrCreate(['patient_id' => $patient->id]);

        return $patient;
    }

    protected function makeTreatment(string $name = 'Endodoncia', int $minutes = 60, string $status = 'active'): Treatment
    {
        return Treatment::create([
            'name' => $name,
            'duration_minutes' => $minutes,
            'price' => '1000.0000',
            'currency' => 'MXN',
            'status' => $status,
        ]);
    }

    /** Cita directa en BD (permite fechas pasadas). */
    protected function makeAppointment(Patient $patient, Carbon $start, int $minutes = 60, AppointmentStatus $status = AppointmentStatus::Pendiente, ?Treatment $treatment = null, ?string $doctor = null): Appointment
    {
        return Appointment::create([
            'branch_id' => $this->mainBranchId(),
            'patient_id' => $patient->id,
            'doctor_member_id' => $doctor ?? $this->doctorMemberId,
            'treatment_id' => ($treatment ?? $this->makeTreatment('Trat '.Str::random(4)))->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($minutes),
            'status' => $status,
            'created_by' => $this->userByEmail('admin@dental.test')->id,
        ]);
    }

    /** @return array<string, mixed> datos válidos para POST citas.store */
    protected function validPayload(Patient $patient, Treatment $treatment, array $override = []): array
    {
        $day = Carbon::now()->addDays(2);

        return array_merge([
            'patient_id' => $patient->id,
            'doctor_member_id' => $this->doctorMemberId,
            'treatment_id' => $treatment->id,
            'date' => $day->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'notes' => 'Primera visita',
        ], $override);
    }

    /** Usuario con membresía activa pero sin ningún permiso. */
    protected function userWithoutPermissions(): \App\Models\User
    {
        $org = $this->organizationId();
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'organization_id' => $org, 'code' => 'sin_permisos', 'name' => 'Sin permisos', 'status' => 'active']);
        $userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $userId, 'full_name' => 'Sin Permisos', 'email' => 'nada@dental.test', 'is_active' => true, 'password' => bcrypt('password')]);
        DB::table('organization_members')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'user_id' => $userId, 'role_id' => $roleId, 'is_active' => true]);

        return \App\Models\User::findOrFail($userId);
    }
}

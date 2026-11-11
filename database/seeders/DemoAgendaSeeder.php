<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\ClinicalEncounter;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Support\Folio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Datos demo del módulo Agenda: doctores adicionales, pacientes y tratamientos
 * mínimos (solo si faltan) y ~3 semanas de citas coherentes (sin empalmes,
 * con historial de estados y consulta clínica para las ya atendidas).
 * Idempotente: si ya existen citas, no hace nada.
 */
class DemoAgendaSeeder extends Seeder
{
    private const DOCTORS = [
        ['andres.cervantes@dental.test', 'Dr. Andrés Cervantes', '2345678', 'Endodoncia'],
        ['lucia.fonseca@dental.test', 'Dra. Lucía Fonseca', '3456789', 'Odontopediatría'],
        ['hugo.salazar@dental.test', 'Dr. Hugo Salazar', '4567890', 'Implantología'],
        ['paola.ibarra@dental.test', 'Dra. Paola Ibarra', '5678901', 'Ortodoncia'],
    ];

    private const PATIENTS = [
        'Roberto Mendoza Lira', 'Renata Aguilar Vega', 'Daniela Vargas Pineda', 'Patricio Guerra Méndez',
        'Valeria Domínguez Soto', 'Jorge Salinas Ruiz', 'Fernanda Lozano Prieto', 'Sergio Naranjo Beltrán',
        'Itzel Romero Campos', 'Camila Ortega Luna', 'Emilio Cárdenas Ibarra', 'Mariana Téllez Robles',
    ];

    private const TREATMENTS = [
        ['Limpieza dental', 30, '350.00'], ['Revisión', 30, '300.00'], ['Resina', 45, '900.00'],
        ['Endodoncia', 60, '3200.00'], ['Corona', 60, '4500.00'], ['Ajuste de ortodoncia', 30, '700.00'],
        ['Extracción', 45, '1200.00'], ['Blanqueamiento', 60, '2800.00'],
    ];

    public function run(): void
    {
        if (Appointment::query()->exists()) {
            return;
        }

        mt_srand(20260606); // datos repetibles

        $orgId = Organization::currentId();
        $branchId = DB::table('branches')->where('organization_id', $orgId)->orderByDesc('is_main')->value('id');
        $adminId = DB::table('users')->where('email', 'admin@dental.test')->value('id');
        if (! $orgId || ! $branchId) {
            return;
        }

        $doctorIds = $this->doctors($orgId, $branchId);
        $patients = $this->patients();
        $treatments = $this->treatments();
        if (! $doctorIds || $patients->isEmpty() || $treatments->isEmpty()) {
            return;
        }

        $this->appointments($doctorIds, $patients, $treatments, $branchId, $adminId);
    }

    /** @return list<string> ids de organization_members con rol doctor */
    private function doctors(string $orgId, string $branchId): array
    {
        $roleId = DB::table('roles')->where('organization_id', $orgId)->where('code', 'doctor')->value('id');
        $hasPassword = Schema::hasColumn('users', 'password');

        foreach (self::DOCTORS as [$email, $name, $license, $specialty]) {
            $userId = DB::table('users')->where('email', $email)->value('id');
            if (! $userId) {
                $userId = (string) Str::uuid();
                $row = ['id' => $userId, 'full_name' => $name, 'email' => $email, 'is_active' => true];
                if ($hasPassword) {
                    $row['password'] = Hash::make('password');
                }
                DB::table('users')->insert($row);
            }

            $memberId = DB::table('organization_members')->where('organization_id', $orgId)->where('user_id', $userId)->value('id');
            if (! $memberId) {
                $memberId = (string) Str::uuid();
                DB::table('organization_members')->insert([
                    'id' => $memberId, 'organization_id' => $orgId, 'user_id' => $userId,
                    'role_id' => $roleId, 'is_active' => true,
                ]);
            }

            DB::table('member_branches')->insertOrIgnore([
                'organization_id' => $orgId, 'member_id' => $memberId, 'branch_id' => $branchId,
            ]);
            DB::table('doctor_profiles')->insertOrIgnore([
                'member_id' => $memberId, 'organization_id' => $orgId,
                'professional_license' => $license, 'specialty' => $specialty,
            ]);
        }

        // Todos los doctores activos de la sucursal (incluye a la Dra. del BaseSeeder).
        return DB::table('organization_members as m')
            ->join('doctor_profiles as d', 'd.member_id', '=', 'm.id')
            ->join('member_branches as b', 'b.member_id', '=', 'm.id')
            ->where('m.organization_id', $orgId)->where('m.is_active', true)->where('b.branch_id', $branchId)
            ->orderBy('m.created_at')->pluck('m.id')->all();
    }

    private function patients()
    {
        if (Patient::query()->count() < 8) {
            foreach (self::PATIENTS as $i => $name) {
                if (Patient::query()->where('full_name', $name)->exists()) {
                    continue;
                }
                $patient = Patient::create([
                    'folio' => Folio::next(Folio::PATIENT),
                    'full_name' => $name,
                    'birth_date' => Carbon::today()->subYears(22 + ($i * 3) % 40)->subDays($i * 17),
                    'sex' => $i % 2 === 0 ? 'female' : 'male',
                    'phone' => '55'.str_pad((string) (10000000 + $i * 913571), 8, '0', STR_PAD_LEFT),
                    'email' => Str::slug($name, '.').'@correo.test',
                ]);
                MedicalRecord::query()->firstOrCreate(['patient_id' => $patient->id]);
            }
        }

        return Patient::query()->active()->orderBy('created_at')->get();
    }

    private function treatments()
    {
        if (Treatment::query()->active()->count() < 5) {
            $tax = TaxProfile::query()->where('is_exempt', false)->first();
            foreach (self::TREATMENTS as [$name, $minutes, $price]) {
                Treatment::query()->firstOrCreate(['name' => $name], [
                    'duration_minutes' => $minutes,
                    'price' => $price,
                    'currency' => 'MXN',
                    'sat_product_key' => '85121800',
                    'sat_unit_key' => 'E48',
                    'tax_profile_id' => $tax?->id,
                    'status' => 'active',
                ]);
            }
        }

        return Treatment::query()->active()->orderBy('name')->get();
    }

    /**
     * Citas de hoy-5 a hoy+14 (sin domingos), sin empalmes de doctor ni paciente.
     */
    private function appointments(array $doctorIds, $patients, $treatments, string $branchId, ?string $adminId): void
    {
        $now = Carbon::now();
        $busyDoctor = [];
        $busyPatient = [];
        $pIndex = 0;

        $free = function (array &$map, string $key, Carbon $s, Carbon $e): bool {
            foreach ($map[$key] ?? [] as [$bs, $be]) {
                if ($s->lt($be) && $e->gt($bs)) {
                    return false;
                }
            }

            return true;
        };

        for ($d = Carbon::today()->subDays(5); $d->lte(Carbon::today()->addDays(14)); $d->addDay()) {
            if ($d->isSunday()) {
                continue;
            }

            foreach ($doctorIds as $doctorId) {
                $count = mt_rand(0, 2);
                for ($n = 0; $n < $count; $n++) {
                    $treatment = $treatments[mt_rand(0, $treatments->count() - 1)];
                    $patient = $patients[$pIndex++ % $patients->count()];
                    $minutes = (int) $treatment->duration_minutes;

                    $start = $d->copy()->setTime(8 + mt_rand(0, 11), [0, 30][mt_rand(0, 1)]);
                    $end = $start->copy()->addMinutes($minutes);
                    if ($end->gt($d->copy()->setTime(21, 0))) {
                        continue;
                    }
                    if (! $free($busyDoctor, $doctorId, $start, $end) || ! $free($busyPatient, $patient->id, $start, $end)) {
                        continue;
                    }
                    $busyDoctor[$doctorId][] = [$start, $end];
                    $busyPatient[$patient->id][] = [$start, $end];

                    $this->createAppointment($doctorId, $patient, $treatment, $branchId, $start, $end, $now, $adminId);
                }
            }
        }
    }

    private function createAppointment(string $doctorId, Patient $patient, Treatment $treatment, string $branchId, Carbon $start, Carbon $end, Carbon $now, ?string $adminId): void
    {
        $roll = mt_rand(1, 100);

        if ($end->lt($now)) {
            $status = $roll <= 80 ? AppointmentStatus::Completada : ($roll <= 90 ? AppointmentStatus::NoAsistio : AppointmentStatus::Cancelada);
        } elseif ($start->lte($now)) {
            $status = AppointmentStatus::EnCurso;
        } else {
            $status = $roll <= 60 ? AppointmentStatus::Confirmada : ($roll <= 95 ? AppointmentStatus::Pendiente : AppointmentStatus::Cancelada);
        }

        $createdAt = $start->copy()->subDays(mt_rand(1, 6))->setTime(mt_rand(9, 18), mt_rand(0, 59));
        if ($createdAt->gt($now)) {
            $createdAt = $now->copy()->subHour();
        }

        $appointment = Appointment::create([
            'branch_id' => $branchId,
            'patient_id' => $patient->id,
            'doctor_member_id' => $doctorId,
            'treatment_id' => $treatment->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => $status,
            'notes' => mt_rand(1, 6) === 1 ? 'El paciente prefiere horario de la mañana.' : null,
            'created_by' => $adminId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // Historial coherente con el estado final.
        $steps = [[null, AppointmentStatus::Pendiente, null]];
        if (in_array($status, [AppointmentStatus::Confirmada, AppointmentStatus::EnCurso, AppointmentStatus::Completada], true)) {
            $steps[] = [AppointmentStatus::Pendiente, AppointmentStatus::Confirmada, null];
        }
        if (in_array($status, [AppointmentStatus::EnCurso, AppointmentStatus::Completada], true)) {
            $steps[] = [AppointmentStatus::Confirmada, AppointmentStatus::EnCurso, null];
        }
        if ($status === AppointmentStatus::Completada) {
            $steps[] = [AppointmentStatus::EnCurso, AppointmentStatus::Completada, null];
        }
        if ($status === AppointmentStatus::NoAsistio) {
            $steps[] = [AppointmentStatus::Pendiente, AppointmentStatus::NoAsistio, null];
        }
        if ($status === AppointmentStatus::Cancelada) {
            $steps[] = [AppointmentStatus::Pendiente, AppointmentStatus::Cancelada, 'El paciente solicitó cancelar.'];
        }

        foreach ($steps as $i => [$from, $to, $reason]) {
            $at = $i === 0 ? $createdAt : $start->copy()->subHours(max(1, 12 - $i * 4));
            AppointmentStatusHistory::create([
                'appointment_id' => $appointment->id,
                'from_status' => $from,
                'to_status' => $to,
                'new_starts_at' => $i === 0 ? $start : null,
                'reason' => $reason,
                'actor_user_id' => $adminId,
                'created_at' => $at->gt($now) ? $now : $at,
            ]);
        }

        // Consulta clínica para las citas ya iniciadas (ligada al expediente).
        if (in_array($status, [AppointmentStatus::EnCurso, AppointmentStatus::Completada], true)) {
            $record = MedicalRecord::query()->firstOrCreate(['patient_id' => $patient->id]);
            ClinicalEncounter::query()->firstOrCreate(['appointment_id' => $appointment->id], [
                'medical_record_id' => $record->id,
                'branch_id' => $branchId,
                'doctor_member_id' => $doctorId,
                'occurred_at' => $start,
            ]);
        }
    }
}

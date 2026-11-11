<?php

namespace Tests\Feature\QA\Clinico;

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Invariantes de coherencia del flujo clínico sobre los datos demo de todos los módulos. */
class DemoDataCoherenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_every_patient_has_complete_valid_contact_data_and_one_medical_record(): void
    {
        $this->assertSame(0, DB::table('patients')->where(fn ($q) => $q->whereNull('phone')->orWhereNull('birth_date')->orWhereRaw("phone NOT REGEXP '^[0-9]{10}$'"))->count(),
            'pacientes con teléfono/fecha de nacimiento inválidos');
        $this->assertSame(0, DB::table('patients as p')->leftJoin('medical_records as m', 'm.patient_id', '=', 'p.id')->whereNull('m.id')->count(), 'paciente sin expediente');
        $this->assertSame(0, DB::table('medical_records')->select('patient_id')->groupBy('patient_id')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    public function test_minors_have_a_registered_guardian(): void
    {
        $orphans = DB::table('patients as p')->leftJoin('patient_guardians as g', 'g.patient_id', '=', 'p.id')
            ->whereRaw('p.birth_date > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)')->whereNull('g.id')->count();
        $this->assertSame(0, $orphans);
    }

    public function test_started_and_completed_appointments_have_exactly_one_encounter_consistent_with_them(): void
    {
        $this->assertSame(0, DB::table('clinical_encounters')->whereNotNull('appointment_id')->select('appointment_id')->groupBy('appointment_id')->havingRaw('COUNT(*) > 1')->get()->count(), 'consultas duplicadas');
        $this->assertSame(0, DB::table('appointments as a')->leftJoin('clinical_encounters as e', 'e.appointment_id', '=', 'a.id')
            ->whereIn('a.status', ['completada', 'en_curso'])->whereNull('e.id')->count(), 'cita atendida sin consulta');
        $this->assertSame(0, DB::table('clinical_encounters as e')->join('appointments as a', 'a.id', '=', 'e.appointment_id')
            ->join('medical_records as m', 'm.id', '=', 'e.medical_record_id')
            ->whereRaw('e.doctor_member_id <> a.doctor_member_id OR e.branch_id <> a.branch_id OR m.patient_id <> a.patient_id')->count(), 'consulta incoherente con su cita');
    }

    public function test_every_appointment_has_status_history_ending_in_its_current_status(): void
    {
        $missing = DB::table('appointments as a')->leftJoin('appointment_status_history as h', 'h.appointment_id', '=', 'a.id')->whereNull('h.id')->count();
        $this->assertSame(0, $missing, 'cita sin historial');
        $wrong = DB::table('appointments as a')->whereRaw('a.status <> (SELECT h.to_status FROM appointment_status_history h WHERE h.appointment_id = a.id ORDER BY h.created_at DESC, h.id DESC LIMIT 1)')->count();
        $this->assertSame(0, $wrong, 'el último estado del historial no coincide con el estado actual');
    }

    public function test_signed_notes_and_odontogram_are_consistent(): void
    {
        $this->assertSame(0, DB::table('clinical_notes')->where('status', 'firmada')->where(fn ($q) => $q->whereNull('content_hash')->orWhereNull('signed_at')->orWhereNull('signed_by_member_id'))->count());
        $this->assertSame(0, DB::table('clinical_notes as n')->leftJoin('doctor_profiles as d', 'd.member_id', '=', 'n.responsible_doctor_member_id')->whereNull('d.member_id')->count(), 'nota con responsable que no es doctor');
        $this->assertSame(0, DB::table('tooth_findings')->whereNull('superseded_at')->select('patient_id', 'tooth_fdi')->groupBy('patient_id', 'tooth_fdi')->havingRaw('COUNT(*) > 1')->get()->count());
        $this->assertSame(0, DB::table('clinical_files')->whereNull('sha256')->count());
    }

    public function test_patient_listing_balance_matches_invoices_minus_applications(): void
    {
        $admin = $this->actingAsAdmin();
        $rows = app(\App\Services\Pacientes\PatientService::class)->listingQuery()->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $p) {
            $invoiced = DB::table('invoices')->where('patient_id', $p->id)->whereNotIn('fiscal_status', ['borrador', 'cancelada'])->sum('total');
            $applied = DB::table('payment_applications as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')->where('i.patient_id', $p->id)
                ->whereNull('a.reversed_at')->whereNotIn('i.fiscal_status', ['borrador', 'cancelada'])->sum('a.applied_amount');
            $this->assertEqualsWithDelta(max(0, $invoiced - $applied), (float) \App\Services\Pacientes\PatientService::balanceOf($p), 0.005, "saldo de {$p->folio}");
        }
        $this->assertNotNull($admin);
    }
}

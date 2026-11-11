<?php

namespace Tests\Feature\Agenda;

use App\Enums\AppointmentStatus;
use Illuminate\Support\Carbon;

class AgendaPagesTest extends AgendaTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('agenda.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_agenda_permission_get_403(): void
    {
        $this->actingAs($this->userWithoutPermissions());

        $this->get(route('agenda.index'))->assertForbidden();
        $this->post(route('citas.store'), [])->assertForbidden();
    }

    public function test_week_view_renders_with_appointments_and_doctor_chips(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient('Renata Aguilar Vega');
        $start = Carbon::now()->startOfWeek()->addDay()->setTime(11, 0);
        $this->makeAppointment($patient, $start, 60, AppointmentStatus::Confirmada, $this->makeTreatment('Limpieza dental', 30));

        $this->get(route('agenda.index', ['view' => 'week', 'date' => $start->toDateString()]))
            ->assertOk()
            ->assertSee('Agenda')
            ->assertSee('Renata Aguilar Vega')
            ->assertSee('Limpieza dental')
            ->assertSee('Mariana Reyes')
            ->assertSee('Nueva cita');
    }

    public function test_new_appointment_modal_exposes_distinct_hooks_for_the_script(): void
    {
        $this->actingAsAdmin();
        $this->makeTreatment('Endodoncia', 60);

        // El campo de duración y las opciones de tratamiento no deben compartir atributo.
        $this->get(route('agenda.index'))->assertOk()
            ->assertSee('data-duration-input', false)
            ->assertSee('data-time-input', false)
            ->assertSee('data-duration="60"', false)
            ->assertSee('Endodoncia · 60 min');
    }

    public function test_day_and_month_views_render(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient('Daniela Vargas Pineda');
        $start = Carbon::now()->addDay()->setTime(9, 30);
        $this->makeAppointment($patient, $start);

        $this->get(route('agenda.index', ['view' => 'day', 'date' => $start->toDateString()]))
            ->assertOk()->assertSee('Daniela Vargas Pineda');

        $this->get(route('agenda.index', ['view' => 'month', 'date' => $start->toDateString()]))
            ->assertOk()->assertSee('Daniela Vargas Pineda');
    }

    public function test_invalid_query_parameters_do_not_break_the_page(): void
    {
        $this->actingAsAdmin();

        $this->get(route('agenda.index', ['view' => 'año', 'date' => 'ayer', 'doctor' => 'x', 'status' => 'zzz', 'patient' => '1']))
            ->assertOk();
    }

    public function test_status_filter_hides_cancelled_by_default_and_shows_them_on_demand(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient('Paciente Cancelado');
        $start = Carbon::now()->addDays(3)->setTime(12, 0);
        $this->makeAppointment($patient, $start, 30, AppointmentStatus::Cancelada);

        $params = ['view' => 'day', 'date' => $start->toDateString()];

        $this->get(route('agenda.index', $params))->assertOk()->assertDontSee('Paciente Cancelado');
        $this->get(route('agenda.index', $params + ['status' => 'todas']))->assertOk()->assertSee('Paciente Cancelado');
    }

    public function test_doctor_filter_only_shows_that_doctors_appointments(): void
    {
        $this->actingAsAdmin();
        $start = Carbon::now()->addDays(2)->setTime(10, 0);
        $this->makeAppointment($this->makePatient('Paciente De Mariana'), $start);

        // Otro doctor (sin citas)
        $otherUser = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $otherUser, 'full_name' => 'Dr. Otro', 'email' => 'otro@dental.test', 'is_active' => true]);
        $otherMember = (string) \Illuminate\Support\Str::uuid();
        $roleId = \Illuminate\Support\Facades\DB::table('roles')->where('code', 'doctor')->value('id');
        \Illuminate\Support\Facades\DB::table('organization_members')->insert(['id' => $otherMember, 'organization_id' => $this->organizationId(), 'user_id' => $otherUser, 'role_id' => $roleId, 'is_active' => true]);
        \Illuminate\Support\Facades\DB::table('member_branches')->insert(['organization_id' => $this->organizationId(), 'member_id' => $otherMember, 'branch_id' => $this->mainBranchId()]);

        $params = ['view' => 'day', 'date' => $start->toDateString()];
        $this->get(route('agenda.index', $params + ['doctor' => $otherMember]))->assertOk()->assertDontSee('Paciente De Mariana');
        $this->get(route('agenda.index', $params + ['doctor' => $this->doctorMemberId]))->assertOk()->assertSee('Paciente De Mariana');
    }

    public function test_preselected_patient_prefills_the_new_appointment_modal(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient('Patricio Guerra Méndez');

        $this->get(route('agenda.index', ['patient' => $patient->id]))
            ->assertOk()
            ->assertSee('Patricio Guerra Méndez ('.$patient->folio.')', false);
    }

    public function test_simultaneous_appointments_of_two_doctors_share_the_day_without_overlap_error(): void
    {
        $this->actingAsAdmin();
        $start = Carbon::now()->addDays(2)->setTime(10, 0);
        $adminMember = $this->memberId($this->userByEmail('admin@dental.test'));
        \Illuminate\Support\Facades\DB::table('doctor_profiles')->insert(['member_id' => $adminMember, 'organization_id' => $this->organizationId(), 'professional_license' => '999']);
        \Illuminate\Support\Facades\DB::table('member_branches')->insertOrIgnore(['organization_id' => $this->organizationId(), 'member_id' => $adminMember, 'branch_id' => $this->mainBranchId()]);

        $this->makeAppointment($this->makePatient('Paciente Uno'), $start);
        $this->makeAppointment($this->makePatient('Paciente Dos'), $start, 60, AppointmentStatus::Pendiente, null, $adminMember);

        $this->get(route('agenda.index', ['view' => 'day', 'date' => $start->toDateString()]))
            ->assertOk()->assertSee('Paciente Uno')->assertSee('Paciente Dos');
    }

    public function test_patient_search_returns_matching_patients_as_json(): void
    {
        $this->actingAsAdmin();
        $p = $this->makePatient('Valeria Domínguez Soto');
        $this->makePatient('Otra Persona');

        $this->getJson(route('agenda.patients', ['q' => 'Valeria']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p->id)
            ->assertJsonPath('data.0.folio', $p->folio);

        $this->getJson(route('agenda.patients', ['q' => 'V']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_slots_endpoint_excludes_busy_times_and_validates_input(): void
    {
        $this->actingAsAdmin();
        $day = Carbon::now()->addDays(3)->startOfDay();
        $this->makeAppointment($this->makePatient('Ocupado'), $day->copy()->setTime(10, 0), 60);

        $res = $this->getJson(route('agenda.slots', [
            'doctor_member_id' => $this->doctorMemberId, 'date' => $day->toDateString(), 'duration_minutes' => 30,
        ]))->assertOk();

        $slots = $res->json('data');
        $this->assertContains('08:00', $slots);
        $this->assertContains('09:30', $slots);
        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('10:30', $slots);
        $this->assertContains('11:00', $slots);
        $this->assertNotContains('20:45', $slots); // 20:45 + 30 min rebasa las 21:00

        $this->getJson(route('agenda.slots', ['date' => $day->toDateString()]))->assertStatus(422);
        $this->getJson(route('agenda.slots', ['doctor_member_id' => $this->doctorMemberId, 'date' => '31/12/2026']))->assertStatus(422);
    }
}

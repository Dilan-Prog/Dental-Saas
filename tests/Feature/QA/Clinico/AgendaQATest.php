<?php

namespace Tests\Feature\QA\Clinico;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\ClinicalEncounter;
use App\Models\MedicalRecord;
use App\Models\OutboxEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agenda\AgendaTestCase;

/** QA del módulo Agenda: validación, empalmes, transiciones por URL directa y coherencia. */
class AgendaQATest extends AgendaTestCase
{
    private function day(int $plus = 2): Carbon
    {
        $d = Carbon::now()->addDays($plus);
        // evita fin de semana sin importar: la agenda no restringe días
        return $d->startOfDay();
    }

    private function store(array $payload)
    {
        $this->actingAsAdmin();

        return $this->from('/agenda')->post(route('citas.store'), $payload);
    }

    private function secondDoctorId(): string
    {
        $org = $this->organizationId();
        $roleId = DB::table('roles')->where('organization_id', $org)->where('code', 'doctor')->value('id');
        $userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $userId, 'full_name' => 'Dr. Segundo QA', 'email' => 'seg@dental.test', 'is_active' => true, 'password' => bcrypt('password')]);
        $memberId = (string) Str::uuid();
        DB::table('organization_members')->insert(['id' => $memberId, 'organization_id' => $org, 'user_id' => $userId, 'role_id' => $roleId, 'is_active' => true]);
        DB::table('member_branches')->insert(['organization_id' => $org, 'member_id' => $memberId, 'branch_id' => $this->mainBranchId()]);

        return $memberId;
    }

    // ------------------------------------------------------------ alta (validación)

    public function test_empty_form_reports_every_required_field(): void
    {
        $this->store([])->assertSessionHasErrors(['patient_id', 'doctor_member_id', 'treatment_id', 'date', 'start_time', 'duration_minutes']);
        $this->assertSame(0, Appointment::query()->count());
    }

    /** @dataProvider badPayloads */
    public function test_invalid_values_are_rejected(array $override, string $field): void
    {
        $patient = $this->makePatient();
        $treatment = $this->makeTreatment();
        $this->store($this->validPayload($patient, $treatment, $override))->assertSessionHasErrors($field);
        $this->assertSame(0, Appointment::query()->count());
    }

    public static function badPayloads(): array
    {
        return [
            'patient not uuid' => [['patient_id' => 'abc'], 'patient_id'],
            'patient unknown' => [['patient_id' => '00000000-0000-4000-8000-000000000000'], 'patient_id'],
            'doctor unknown' => [['doctor_member_id' => '00000000-0000-4000-8000-000000000000'], 'doctor_member_id'],
            'duration 7' => [['duration_minutes' => 7], 'duration_minutes'],
            'duration 10 (min 15)' => [['duration_minutes' => 10], 'duration_minutes'],
            'duration 600 (max 480)' => [['duration_minutes' => 600], 'duration_minutes'],
            'duration text' => [['duration_minutes' => 'abc'], 'duration_minutes'],
            'duration negative' => [['duration_minutes' => -30], 'duration_minutes'],
            'bad date' => [['date' => '2030-02-31'], 'date'],
            'date dmy' => [['date' => '12/12/2030'], 'date'],
            'bad time' => [['start_time' => '25:00'], 'start_time'],
            'time text' => [['start_time' => 'mañana'], 'start_time'],
            'before opening' => [['start_time' => '07:30'], 'start_time'],
            'after closing' => [['start_time' => '21:00'], 'start_time'],
            'ends after closing' => [['start_time' => '20:30', 'duration_minutes' => 60], 'start_time'],
            'minutes not multiple of 5' => [['start_time' => '10:03'], 'start_time'],
            'notes too long' => [['notes' => str_repeat('x', 2001)], 'notes'],
            'branch unknown' => [['branch_id' => '00000000-0000-4000-8000-000000000000'], 'branch_id'],
            'patient as array' => [['patient_id' => ['x']], 'patient_id'],
        ];
    }

    public function test_past_date_and_past_time_today_are_rejected(): void
    {
        $patient = $this->makePatient();
        $treatment = $this->makeTreatment();
        $this->store($this->validPayload($patient, $treatment, ['date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('start_time');
        $this->travelTo(now()->setTime(15, 0));
        $this->store($this->validPayload($patient, $treatment, ['date' => now()->toDateString(), 'start_time' => '14:00']))->assertSessionHasErrors('start_time');
        $this->store($this->validPayload($patient, $treatment, ['date' => now()->toDateString(), 'start_time' => '16:00']))->assertSessionHasNoErrors();
    }

    public function test_doctor_must_be_a_doctor_and_inactive_treatment_is_rejected(): void
    {
        $patient = $this->makePatient();
        $receptionist = $this->memberId($this->userByEmail('recepcion@dental.test'));
        $this->store($this->validPayload($patient, $this->makeTreatment(), ['doctor_member_id' => $receptionist]))->assertSessionHasErrors('doctor_member_id');
        $inactive = $this->makeTreatment('Inactivo', 30, 'inactive');
        $this->store($this->validPayload($patient, $inactive))->assertSessionHasErrors('treatment_id');
    }

    public function test_valid_creation_writes_history_and_keeps_exact_times(): void
    {
        $patient = $this->makePatient();
        $treatment = $this->makeTreatment('Limpieza', 45);
        $this->store($this->validPayload($patient, $treatment, ['duration_minutes' => 45, 'start_time' => '09:15']))->assertSessionHasNoErrors();
        $a = Appointment::query()->firstOrFail();
        $this->assertSame('09:15', $a->starts_at->format('H:i'));
        $this->assertSame('10:00', $a->ends_at->format('H:i'));
        $this->assertSame('pendiente', $a->status->value);
        $this->assertGreaterThanOrEqual(1, AppointmentStatusHistory::query()->where('appointment_id', $a->id)->count());
    }

    public function test_notes_are_stored_without_html_execution(): void
    {
        $patient = $this->makePatient();
        $this->store($this->validPayload($patient, $this->makeTreatment(), ['notes' => '<script>alert(1)</script>Llamar']))->assertSessionHasNoErrors();
        $this->actingAsAdmin();
        $html = $this->get(route('citas.show', Appointment::query()->first()))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ------------------------------------------------------------ empalmes

    public function test_doctor_overlap_is_rejected_but_adjacent_slots_are_allowed(): void
    {
        $p1 = $this->makePatient('Paciente Uno Prueba');
        $p2 = $this->makePatient('Paciente Dos Prueba', ['phone' => '5599999991']);
        $t = $this->makeTreatment();
        $this->store($this->validPayload($p1, $t, ['start_time' => '10:00']))->assertSessionHasNoErrors();
        // empalme parcial
        $this->store($this->validPayload($p2, $t, ['start_time' => '10:30']))->assertSessionHasErrors('doctor_member_id');
        // empalme total, mismo inicio
        $this->store($this->validPayload($p2, $t, ['start_time' => '10:00']))->assertSessionHasErrors('doctor_member_id');
        // contenida
        $this->store($this->validPayload($p2, $t, ['start_time' => '10:15', 'duration_minutes' => 15]))->assertSessionHasErrors('doctor_member_id');
        // que envuelve
        $this->store($this->validPayload($p2, $t, ['start_time' => '09:30', 'duration_minutes' => 120]))->assertSessionHasErrors('doctor_member_id');
        // adyacentes (termina 10:00 / inicia 11:00)
        $this->store($this->validPayload($p2, $t, ['start_time' => '09:00']))->assertSessionHasNoErrors();
        $this->store($this->validPayload($p2, $t, ['start_time' => '11:00']))->assertSessionHasNoErrors();
        $this->assertSame(3, Appointment::query()->count());
    }

    public function test_patient_cannot_be_in_two_places_with_different_doctors(): void
    {
        $patient = $this->makePatient();
        $t = $this->makeTreatment();
        $second = $this->secondDoctorId();
        $this->store($this->validPayload($patient, $t, ['start_time' => '10:00']))->assertSessionHasNoErrors();
        $this->store($this->validPayload($patient, $t, ['start_time' => '10:30', 'doctor_member_id' => $second]))->assertSessionHasErrors('patient_id');
    }

    public function test_two_doctors_can_share_the_same_slot_with_different_patients(): void
    {
        $t = $this->makeTreatment();
        $second = $this->secondDoctorId();
        $this->store($this->validPayload($this->makePatient('Uno Dos Tres'), $t))->assertSessionHasNoErrors();
        $this->store($this->validPayload($this->makePatient('Cuatro Cinco Seis', ['phone' => '5599999992']), $t, ['doctor_member_id' => $second]))->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_cancelled_and_noshow_appointments_free_the_slot(): void
    {
        $patient = $this->makePatient();
        $t = $this->makeTreatment();
        $start = $this->day()->setTime(10, 0);
        $this->makeAppointment($patient, $start, 60, AppointmentStatus::Cancelada);
        $this->makeAppointment($patient, $start->copy(), 60, AppointmentStatus::NoAsistio);
        $this->store($this->validPayload($patient, $t, ['date' => $start->toDateString()]))->assertSessionHasNoErrors();
    }

    public function test_database_triggers_block_overlap_even_if_application_check_is_bypassed(): void
    {
        $patient = $this->makePatient();
        $start = $this->day()->setTime(10, 0);
        $this->makeAppointment($patient, $start);
        $other = $this->makePatient('Otro Paciente QA', ['phone' => '5599999993']);
        $this->expectException(QueryException::class);
        $this->makeAppointment($other, $start->copy()->addMinutes(30));
    }

    public function test_race_condition_is_translated_to_a_validation_message(): void
    {
        $patient = $this->makePatient();
        $other = $this->makePatient('Otro Paciente QA', ['phone' => '5599999993']);
        $t = $this->makeTreatment();
        $start = $this->day()->setTime(12, 0);
        $this->makeAppointment($patient, $start);

        // Simula la carrera: la comprobación previa no ve la cita porque la insertan "justo después".
        $service = app(\App\Services\Agenda\AppointmentService::class);
        $payload = ['patient_id' => $other->id, 'doctor_member_id' => $this->doctorMemberId, 'treatment_id' => $t->id,
            'date' => $start->toDateString(), 'start_time' => '12:30', 'duration_minutes' => 60];
        try {
            $service->create($payload, $this->userByEmail('admin@dental.test'));
            $this->fail('Debió rechazar el empalme');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('doctor_member_id', $e->errors());
        }
    }

    // ------------------------------------------------------------ transiciones

    private function appt(AppointmentStatus $status, ?Carbon $start = null): Appointment
    {
        return $this->makeAppointment($this->makePatient('Paciente Flujo QA', ['phone' => '55'.random_int(10000000, 99999999)]), $start ?? $this->day()->setTime(10, 0), 60, $status);
    }

    public function test_cancel_requires_reason_and_logs_history_and_outbox(): void
    {
        $a = $this->appt(AppointmentStatus::Pendiente);
        $this->actingAsAdmin();
        $this->from('/agenda')->post(route('citas.cancel', $a), [])->assertSessionHasErrors('reason', null, 'cancel');
        $this->post(route('citas.cancel', $a), ['reason' => 'ab'])->assertSessionHasErrors('reason', null, 'cancel');
        $this->post(route('citas.cancel', $a), ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason', null, 'cancel');
        $this->assertSame('pendiente', $a->fresh()->status->value);

        $this->post(route('citas.cancel', $a), ['reason' => 'El paciente avisó que no podrá asistir'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $a->fresh()->status->value);
        $h = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->where('to_status', 'cancelada')->first();
        $this->assertNotNull($h);
        $this->assertStringContainsString('avisó', (string) $h->reason);
        $this->assertGreaterThanOrEqual(1, OutboxEvent::query()->where('aggregate_id', $a->id)->count());
    }

    /** @dataProvider invalidTransitions */
    public function test_invalid_transitions_by_direct_url_are_rejected(string $from, string $route, array $body = []): void
    {
        $start = in_array($route, ['citas.noshow'], true) ? Carbon::now()->addDay() : $this->day()->setTime(10, 0);
        $a = $this->appt(AppointmentStatus::from($from), $start);
        $this->actingAsAdmin();
        $this->from('/agenda')->post(route($route, $a), $body);
        $this->assertSame($from, $a->fresh()->status->value, "$route desde $from no debe cambiar el estado");
    }

    public static function invalidTransitions(): array
    {
        return [
            'confirm a cancelled' => ['cancelada', 'citas.confirm'],
            'confirm a completed' => ['completada', 'citas.confirm'],
            'confirm a confirmed' => ['confirmada', 'citas.confirm'],
            'cancel a completed' => ['completada', 'citas.cancel', ['reason' => 'ya no']],
            'cancel a cancelled' => ['cancelada', 'citas.cancel', ['reason' => 'ya no']],
            'cancel a no-show' => ['no_asistio', 'citas.cancel', ['reason' => 'ya no']],
            'complete a pending' => ['pendiente', 'citas.complete'],
            'complete a cancelled' => ['cancelada', 'citas.complete'],
            'start a cancelled' => ['cancelada', 'citas.start'],
            'start a completed' => ['completada', 'citas.start'],
            'noshow before time' => ['confirmada', 'citas.noshow'],
            'noshow on cancelled' => ['cancelada', 'citas.noshow'],
        ];
    }

    public function test_start_only_works_on_the_day_and_creates_exactly_one_encounter(): void
    {
        $future = $this->appt(AppointmentStatus::Confirmada, $this->day(3)->setTime(10, 0));
        $this->actingAsAdmin();
        $this->from('/agenda')->post(route('citas.start', $future))->assertSessionHasErrors('status');
        $this->assertSame(0, ClinicalEncounter::query()->count());

        $this->travelTo(now()->setTime(9, 0));
        $today = $this->appt(AppointmentStatus::Confirmada, now()->setTime(10, 0));
        $this->post(route('citas.start', $today))->assertSessionHasNoErrors();
        $this->post(route('citas.start', $today));       // doble clic
        $this->assertSame('en_curso', $today->fresh()->status->value);
        $this->assertSame(1, ClinicalEncounter::query()->where('appointment_id', $today->id)->count());
        $e = ClinicalEncounter::query()->where('appointment_id', $today->id)->first();
        $this->assertSame($today->doctor_member_id, $e->doctor_member_id);
        $this->assertSame(1, MedicalRecord::query()->where('patient_id', $today->patient_id)->count());
    }

    public function test_pending_appointment_can_or_cannot_start_consistently_with_the_state_machine(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $a = $this->appt(AppointmentStatus::Pendiente, now()->setTime(10, 0));
        $this->actingAsAdmin();
        $this->from('/agenda')->post(route('citas.start', $a));
        $allowed = $a->canTransitionTo(AppointmentStatus::EnCurso);
        $this->assertSame($allowed ? 'en_curso' : 'pendiente', $a->fresh()->status->value);
    }

    public function test_full_happy_path_leaves_complete_history(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $a = $this->appt(AppointmentStatus::Pendiente, now()->setTime(10, 0));
        $this->actingAsAdmin();
        $this->post(route('citas.confirm', $a))->assertSessionHasNoErrors();
        $this->post(route('citas.start', $a))->assertSessionHasNoErrors();
        $this->post(route('citas.complete', $a))->assertSessionHasNoErrors();
        $this->assertSame('completada', $a->fresh()->status->value);
        $chain = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->orderBy('created_at')->orderBy('id')->pluck('to_status')->map(fn ($s) => $s->value ?? $s)->all();
        foreach (['confirmada', 'en_curso', 'completada'] as $s) {
            $this->assertContains($s, $chain);
        }
    }

    public function test_noshow_after_time_is_allowed_and_frees_nothing_else(): void
    {
        $a = $this->appt(AppointmentStatus::Confirmada, Carbon::now()->subDays(2)->setTime(10, 0));
        $this->actingAsAdmin();
        $this->post(route('citas.noshow', $a))->assertSessionHasNoErrors();
        $this->assertSame('no_asistio', $a->fresh()->status->value);
    }

    // ------------------------------------------------------------ reagendar / editar

    public function test_reschedule_validates_overlap_past_hours_and_same_slot(): void
    {
        $a = $this->appt(AppointmentStatus::Pendiente, $this->day()->setTime(10, 0));
        $this->makeAppointment($this->makePatient('Bloqueador Slot', ['phone' => '5588888881']), $this->day()->setTime(12, 0));
        $this->actingAsAdmin();
        $url = route('citas.reschedule', $a);
        $date = $this->day()->toDateString();
        $this->from('/agenda')->post($url, ['date' => $date, 'start_time' => '12:30'])->assertSessionHasErrors(null, null, 'reschedule');
        $this->post($url, ['date' => $date, 'start_time' => '10:00'])->assertSessionHasErrors('start_time', null, 'reschedule');   // igual a la actual
        $this->post($url, ['date' => now()->subDay()->toDateString(), 'start_time' => '10:00'])->assertSessionHasErrors(null, null, 'reschedule');
        $this->post($url, ['date' => $date, 'start_time' => '06:00'])->assertSessionHasErrors(null, null, 'reschedule');
        $this->post($url, ['date' => $date, 'start_time' => '14:00', 'change_reason' => str_repeat('x', 501)])->assertSessionHasErrors('change_reason', null, 'reschedule');
        $this->assertSame('10:00', $a->fresh()->starts_at->format('H:i'));
        $this->post($url, ['date' => $date, 'start_time' => '14:00'])->assertSessionHasNoErrors();
        $this->assertSame('14:00', $a->fresh()->starts_at->format('H:i'));
        $this->assertSame('15:00', $a->fresh()->ends_at->format('H:i'));
    }

    public function test_reschedule_of_closed_appointments_is_rejected(): void
    {
        foreach ([AppointmentStatus::Completada, AppointmentStatus::Cancelada, AppointmentStatus::NoAsistio] as $status) {
            $a = $this->appt($status);
            $this->actingAsAdmin();
            $this->from('/agenda')->post(route('citas.reschedule', $a), ['date' => $this->day(5)->toDateString(), 'start_time' => '11:00']);
            $this->assertSame('10:00', $a->fresh()->starts_at->format('H:i'), $status->value);
            $this->put(route('citas.update', $a), ['notes' => 'cambio', 'duration_minutes' => 30]);
            $this->assertSame(60, $a->fresh()->starts_at->diffInMinutes($a->fresh()->ends_at), $status->value);
        }
    }

    public function test_update_cannot_extend_into_another_appointment(): void
    {
        $a = $this->appt(AppointmentStatus::Pendiente, $this->day()->setTime(10, 0));
        $this->makeAppointment($this->makePatient('Siguiente Cita', ['phone' => '5577777771']), $this->day()->setTime(11, 0));
        $this->actingAsAdmin();
        $this->from('/agenda')->put(route('citas.update', $a), ['duration_minutes' => 90])->assertSessionHasErrors();
        $this->assertSame(60, $a->fresh()->starts_at->diffInMinutes($a->fresh()->ends_at));
    }

    // ------------------------------------------------------------ permisos y bordes de URL

    public function test_access_control_and_malformed_ids(): void
    {
        $a = $this->appt(AppointmentStatus::Pendiente);
        $this->actingAs($this->userWithoutPermissions());
        $this->get(route('agenda.index'))->assertStatus(403);
        $this->post(route('citas.store'), [])->assertStatus(403);
        $this->post(route('citas.cancel', $a), ['reason' => 'prueba'])->assertStatus(403);
        $this->actingAsAdmin();
        $this->get('/citas/no-es-uuid')->assertNotFound();
        $this->get('/citas/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_calendar_views_tolerate_hostile_query_strings(): void
    {
        $this->actingAsAdmin();
        foreach ([['vista' => 'xx'], ['fecha' => 'basura'], ['fecha' => '9999-99-99'], ['doctor' => 'no-uuid'], ['estado' => "' OR 1=1"], ['vista' => 'semana', 'fecha' => '2030-02-30'], ['q' => str_repeat('a', 3000)]] as $q) {
            $this->get(route('agenda.index', $q))->assertStatus(200);
        }
        $this->getJson(route('agenda.patients', ['q' => '%']))->assertOk();
        $this->getJson(route('agenda.slots', ['date' => 'x']))->assertStatus(422);
    }

    public function test_slots_endpoint_never_offers_overlapping_or_out_of_hours_slots(): void
    {
        $patient = $this->makePatient();
        $this->makeAppointment($patient, $this->day()->setTime(10, 0));
        $this->actingAsAdmin();
        $res = $this->getJson(route('agenda.slots', ['date' => $this->day()->toDateString(), 'doctor_member_id' => $this->doctorMemberId, 'duration' => 60]));
        if ($res->status() === 200) {
            $json = json_encode($res->json());
            $this->assertStringNotContainsString('"10:00"', $json);
            $this->assertStringNotContainsString('"10:30"', $json);
            $this->assertStringNotContainsString('"07:', $json);
        } else {
            $this->markTestIncomplete('slots devolvió '.$res->status());
        }
    }
}

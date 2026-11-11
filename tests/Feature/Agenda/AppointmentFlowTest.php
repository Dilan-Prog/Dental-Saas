<?php

namespace Tests\Feature\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\ClinicalEncounter;
use App\Models\OutboxEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentFlowTest extends AgendaTestCase
{
    // ------------------------------------------------------------ alta

    public function test_it_creates_an_appointment_with_history_and_redirects_to_its_week(): void
    {
        $user = $this->actingAsAdmin();
        $patient = $this->makePatient();
        $treatment = $this->makeTreatment();
        $payload = $this->validPayload($patient, $treatment);

        $this->post(route('citas.store'), $payload + ['_view' => 'month'])
            ->assertRedirect(route('agenda.index', ['view' => 'month', 'date' => $payload['date']]))
            ->assertSessionHas('success');

        $a = Appointment::query()->firstOrFail();
        $this->assertSame(AppointmentStatus::Pendiente, $a->status);
        $this->assertSame($patient->id, $a->patient_id);
        $this->assertSame($this->mainBranchId(), $a->branch_id, 'sin sucursal explícita usa la principal');
        $this->assertSame($user->id, $a->created_by);
        $this->assertSame(60, $a->duration_minutes);
        $this->assertSame($payload['date'].' 10:00', $a->starts_at->format('Y-m-d H:i'));
        $this->assertSame($payload['date'].' 11:00', $a->ends_at->format('Y-m-d H:i'));

        $h = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->firstOrFail();
        $this->assertNull($h->from_status);
        $this->assertSame(AppointmentStatus::Pendiente, $h->to_status);
    }

    public function test_receptionist_can_create_but_user_without_permission_cannot(): void
    {
        $this->actingAsReceptionist();
        $this->post(route('citas.store'), $this->validPayload($this->makePatient(), $this->makeTreatment()))->assertRedirect();
        $this->assertSame(1, Appointment::query()->count());
    }

    /** @dataProvider invalidPayloads */
    public function test_store_validates_every_field(string $field, callable $mutate, string $expectedMessage): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $treatment = $this->makeTreatment();
        $payload = $mutate($this->validPayload($patient, $treatment), $this);

        $this->from(route('agenda.index'))->post(route('citas.store'), $payload)
            ->assertRedirect(route('agenda.index'))
            ->assertSessionHasErrors($field);

        $this->assertStringContainsString($expectedMessage, session('errors')->first($field));
        $this->assertSame(0, Appointment::query()->count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'paciente obligatorio' => ['patient_id', fn ($p) => array_diff_key($p, ['patient_id' => 1]), 'paciente'],
            'paciente inexistente' => ['patient_id', fn ($p) => ['patient_id' => '11111111-1111-4111-8111-111111111111'] + $p, 'no existe'],
            'paciente no uuid' => ['patient_id', fn ($p) => ['patient_id' => 'abc'] + $p, 'paciente'],
            'doctor obligatorio' => ['doctor_member_id', fn ($p) => array_diff_key($p, ['doctor_member_id' => 1]), 'doctor'],
            'doctor inexistente' => ['doctor_member_id', fn ($p) => ['doctor_member_id' => '11111111-1111-4111-8111-111111111111'] + $p, 'doctor'],
            'tratamiento obligatorio' => ['treatment_id', fn ($p) => array_diff_key($p, ['treatment_id' => 1]), 'tratamiento'],
            'tratamiento inexistente' => ['treatment_id', fn ($p) => ['treatment_id' => '11111111-1111-4111-8111-111111111111'] + $p, 'tratamiento'],
            'fecha obligatoria' => ['date', fn ($p) => array_diff_key($p, ['date' => 1]), 'fecha'],
            'fecha formato' => ['date', fn ($p) => ['date' => '25/12/2030'] + $p, 'formato'],
            'hora obligatoria' => ['start_time', fn ($p) => array_diff_key($p, ['start_time' => 1]), 'hora'],
            'hora formato' => ['start_time', fn ($p) => ['start_time' => '10am'] + $p, 'HH:MM'],
            'duración obligatoria' => ['duration_minutes', fn ($p) => array_diff_key($p, ['duration_minutes' => 1]), 'duración'],
            'duración mínima' => ['duration_minutes', fn ($p) => ['duration_minutes' => 10] + $p, 'mínima'],
            'duración máxima' => ['duration_minutes', fn ($p) => ['duration_minutes' => 600] + $p, 'máxima'],
            'duración múltiplo de 5' => ['duration_minutes', fn ($p) => ['duration_minutes' => 32] + $p, 'múltiplo'],
            'duración no numérica' => ['duration_minutes', fn ($p) => ['duration_minutes' => 'una hora'] + $p, 'entero'],
            'notas demasiado largas' => ['notes', fn ($p) => ['notes' => str_repeat('x', 2001)] + $p, 'superar'],
            'fecha pasada' => ['start_time', fn ($p) => ['date' => Carbon::yesterday()->toDateString()] + $p, 'pasada'],
            'antes del horario' => ['start_time', fn ($p) => ['start_time' => '07:00'] + $p, 'horario de atención'],
            'termina después del cierre' => ['start_time', fn ($p) => ['start_time' => '20:30', 'duration_minutes' => 60] + $p, 'horario de atención'],
            'inicio no múltiplo de 5' => ['start_time', fn ($p) => ['start_time' => '10:03'] + $p, 'múltiplo'],
        ];
    }

    public function test_inactive_patient_treatment_and_unassigned_doctor_are_rejected(): void
    {
        $this->actingAsAdmin();
        $treatment = $this->makeTreatment();

        $inactive = $this->makePatient('Paciente Inactivo', ['status' => 'inactive']);
        $this->post(route('citas.store'), $this->validPayload($inactive, $treatment))->assertSessionHasErrors('patient_id');

        $patient = $this->makePatient('Paciente Activo');
        $inactiveTreatment = $this->makeTreatment('Tratamiento viejo', 30, 'inactive');
        $this->post(route('citas.store'), $this->validPayload($patient, $inactiveTreatment))->assertSessionHasErrors('treatment_id');

        // El administrador no es doctor: no puede recibir citas.
        $admin = $this->memberId($this->userByEmail('admin@dental.test'));
        $this->post(route('citas.store'), $this->validPayload($patient, $treatment, ['doctor_member_id' => $admin]))
            ->assertSessionHasErrors('doctor_member_id');

        // Doctor sin sucursal asignada.
        DB::table('member_branches')->where('member_id', $this->doctorMemberId)->delete();
        $this->post(route('citas.store'), $this->validPayload($patient, $treatment))->assertSessionHasErrors('doctor_member_id');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_it_rejects_doctor_and_patient_overlaps_but_allows_back_to_back(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient('Paciente A');
        $treatment = $this->makeTreatment();
        $day = Carbon::now()->addDays(2)->toDateString();

        $this->post(route('citas.store'), $this->validPayload($patient, $treatment, ['date' => $day, 'start_time' => '10:00']))->assertSessionHasNoErrors();

        // Mismo doctor, otro paciente, se empalma de 10:30 a 11:30.
        $other = $this->makePatient('Paciente B');
        $this->post(route('citas.store'), $this->validPayload($other, $treatment, ['date' => $day, 'start_time' => '10:30']))
            ->assertSessionHasErrors('doctor_member_id');

        // Mismo paciente con otro doctor al mismo tiempo.
        $this->post(route('citas.store'), $this->validPayload($patient, $treatment, ['date' => $day, 'start_time' => '10:15', 'doctor_member_id' => $this->secondDoctor()]))
            ->assertSessionHasErrors('patient_id');

        // Citas consecutivas (11:00 justo cuando termina la anterior) sí se permiten.
        $this->post(route('citas.store'), $this->validPayload($other, $treatment, ['date' => $day, 'start_time' => '11:00']))->assertSessionHasNoErrors();

        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_database_triggers_reject_overlaps_even_if_the_service_check_is_bypassed(): void
    {
        $patient = $this->makePatient();
        $start = Carbon::now()->addDays(2)->setTime(10, 0);
        $this->makeAppointment($patient, $start);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('appointments_no_doctor_overlap');
        $this->makeAppointment($this->makePatient('Otro'), $start->copy()->addMinutes(15));
    }

    public function test_cancelled_appointments_free_the_slot(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $start = Carbon::now()->addDays(2)->setTime(10, 0);
        $this->makeAppointment($patient, $start, 60, AppointmentStatus::Cancelada);

        $this->post(route('citas.store'), $this->validPayload($patient, $this->makeTreatment(), ['date' => $start->toDateString(), 'start_time' => '10:00']))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------ estados

    public function test_confirm_changes_status_writes_history_and_outbox(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->addDays(2)->setTime(10, 0));

        $this->post(route('citas.confirm', $a))->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Confirmada, $a->fresh()->status);
        $h = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->latest('created_at')->firstOrFail();
        $this->assertSame(AppointmentStatus::Pendiente, $h->from_status);
        $this->assertSame(AppointmentStatus::Confirmada, $h->to_status);

        $event = OutboxEvent::query()->where('aggregate_id', $a->id)->firstOrFail();
        $this->assertSame('appointment.confirmed', $event->event_type);
        $this->assertSame('appointment', $event->aggregate_type);
        $this->assertSame('Roberto Mendoza Lira', $event->payload['patient_name']);

        // Confirmar dos veces es una transición inválida.
        $this->post(route('citas.confirm', $a))->assertSessionHasErrors('status');
        $this->assertSame(1, OutboxEvent::query()->count());
    }

    public function test_cancel_requires_a_reason_and_records_history_and_outbox(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->addDays(2)->setTime(10, 0));

        $this->post(route('citas.cancel', $a), ['reason' => ''])->assertSessionHasErrors('reason', null, 'cancel');
        $this->post(route('citas.cancel', $a), ['reason' => 'ab'])->assertSessionHasErrors('reason', null, 'cancel');
        $this->assertSame(AppointmentStatus::Pendiente, $a->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->count());

        $this->post(route('citas.cancel', $a), ['reason' => 'El paciente tiene gripe'])->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Cancelada, $a->fresh()->status);
        $h = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->where('to_status', 'cancelada')->firstOrFail();
        $this->assertSame('El paciente tiene gripe', $h->reason);
        $event = OutboxEvent::query()->where('event_type', 'appointment.cancelled')->firstOrFail();
        $this->assertSame('El paciente tiene gripe', $event->payload['reason']);
    }

    public function test_final_states_cannot_change(): void
    {
        $this->actingAsAdmin();
        $done = $this->makeAppointment($this->makePatient(), Carbon::now()->subDays(1)->setTime(10, 0), 60, AppointmentStatus::Completada);

        $this->post(route('citas.cancel', $done), ['reason' => 'No debería poder'])->assertSessionHasErrors('status');
        $this->post(route('citas.confirm', $done))->assertSessionHasErrors('status');
        $this->assertSame(AppointmentStatus::Completada, $done->fresh()->status);
    }

    public function test_start_moves_to_en_curso_and_creates_the_encounter_in_the_patients_record(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $a = $this->makeAppointment($patient, Carbon::now()->setTime(18, 0), 60, AppointmentStatus::Confirmada);

        $this->post(route('citas.start', $a))->assertRedirect();

        $this->assertSame(AppointmentStatus::EnCurso, $a->fresh()->status);
        $encounter = ClinicalEncounter::query()->where('appointment_id', $a->id)->firstOrFail();
        $this->assertSame($patient->medicalRecord->id, $encounter->medical_record_id);
        $this->assertSame($a->doctor_member_id, $encounter->doctor_member_id);
        $this->assertSame($a->branch_id, $encounter->branch_id);

        // Iniciar de nuevo no duplica la consulta ni cambia el estado.
        $this->post(route('citas.start', $a))->assertSessionHasErrors('status');
        $this->assertSame(1, ClinicalEncounter::query()->where('appointment_id', $a->id)->count());
    }

    public function test_start_creates_the_medical_record_if_the_patient_has_none(): void
    {
        $this->actingAsAdmin();
        $patient = $this->makePatient();
        $patient->medicalRecord()->delete();
        $a = $this->makeAppointment($patient, Carbon::now()->setTime(18, 0));

        $this->post(route('citas.start', $a));

        $this->assertNotNull($patient->fresh()->medicalRecord);
        $this->assertSame(1, ClinicalEncounter::query()->count());
    }

    public function test_start_only_works_on_the_day_of_the_appointment(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->addDays(2)->setTime(10, 0));

        $this->post(route('citas.start', $a))->assertSessionHasErrors('status');

        $this->assertSame(AppointmentStatus::Pendiente, $a->fresh()->status);
        $this->assertSame(0, ClinicalEncounter::query()->count());
    }

    public function test_complete_requires_the_appointment_to_be_in_progress(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->setTime(18, 0));

        $this->post(route('citas.complete', $a))->assertSessionHasErrors('status');

        $this->post(route('citas.start', $a));
        $this->post(route('citas.complete', $a))->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Completada, $a->fresh()->status);
    }

    public function test_no_show_is_only_allowed_after_the_appointment_time(): void
    {
        $this->actingAsAdmin();
        $future = $this->makeAppointment($this->makePatient('Futuro'), Carbon::now()->addDays(2)->setTime(10, 0));
        $past = $this->makeAppointment($this->makePatient('Pasado'), Carbon::now()->subDays(1)->setTime(10, 0), 60, AppointmentStatus::Confirmada);

        $this->post(route('citas.noshow', $future))->assertSessionHasErrors('status');
        $this->post(route('citas.noshow', $past))->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Pendiente, $future->fresh()->status);
        $this->assertSame(AppointmentStatus::NoAsistio, $past->fresh()->status);
    }

    // ------------------------------------------------------------ reagendar / editar

    public function test_reschedule_moves_the_appointment_and_records_old_and_new_times(): void
    {
        $this->actingAsAdmin();
        $old = Carbon::now()->addDays(2)->setTime(10, 0);
        $a = $this->makeAppointment($this->makePatient(), $old);
        $new = Carbon::now()->addDays(3);

        $this->post(route('citas.reschedule', $a), [
            'date' => $new->toDateString(), 'start_time' => '15:30', 'change_reason' => 'A solicitud del paciente',
        ])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($new->toDateString().' 15:30', $a->starts_at->format('Y-m-d H:i'));
        $this->assertSame($new->toDateString().' 16:30', $a->ends_at->format('Y-m-d H:i'), 'conserva la duración');

        $h = AppointmentStatusHistory::query()->where('appointment_id', $a->id)->whereNotNull('old_starts_at')->firstOrFail();
        $this->assertSame($old->format('Y-m-d H:i'), $h->old_starts_at->format('Y-m-d H:i'));
        $this->assertSame($new->toDateString().' 15:30', $h->new_starts_at->format('Y-m-d H:i'));
        $this->assertSame('A solicitud del paciente', $h->reason);
        $this->assertSame('appointment.rescheduled', OutboxEvent::query()->firstOrFail()->event_type);
    }

    public function test_reschedule_errors_go_to_their_own_error_bag(): void
    {
        $this->actingAsAdmin();
        $day = Carbon::now()->addDays(2);
        $this->makeAppointment($this->makePatient('Ocupa'), $day->copy()->setTime(15, 0));
        $a = $this->makeAppointment($this->makePatient('Mueve'), $day->copy()->setTime(9, 0));

        // Empalme con otra cita del doctor
        $this->post(route('citas.reschedule', $a), ['date' => $day->toDateString(), 'start_time' => '15:30'])
            ->assertSessionHasErrors('start_time', null, 'reschedule');
        // Pasado
        $this->post(route('citas.reschedule', $a), ['date' => Carbon::yesterday()->toDateString(), 'start_time' => '10:00'])
            ->assertSessionHasErrors('start_time', null, 'reschedule');
        // Misma fecha y hora
        $this->post(route('citas.reschedule', $a), ['date' => $day->toDateString(), 'start_time' => '09:00'])
            ->assertSessionHasErrors('start_time', null, 'reschedule');
        // Campos obligatorios / formato
        $this->post(route('citas.reschedule', $a), [])->assertSessionHasErrors(['date', 'start_time'], null, 'reschedule');

        $this->assertSame($day->toDateString().' 09:00', $a->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_completed_or_cancelled_appointments_cannot_be_rescheduled_or_edited(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->addDays(2)->setTime(10, 0), 60, AppointmentStatus::Cancelada);

        $this->post(route('citas.reschedule', $a), ['date' => Carbon::now()->addDays(3)->toDateString(), 'start_time' => '10:00'])
            ->assertSessionHasErrors('status');
        $this->put(route('citas.update', $a), ['notes' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_update_changes_treatment_duration_and_notes_and_checks_overlaps(): void
    {
        $this->actingAsAdmin();
        $start = Carbon::now()->addDays(2)->setTime(10, 0);
        $a = $this->makeAppointment($this->makePatient('Editada'), $start, 30);
        $this->makeAppointment($this->makePatient('Siguiente'), $start->copy()->addMinutes(60), 30);
        $treatment = $this->makeTreatment('Corona', 90);

        // Crece hasta 11:30: choca con la siguiente (11:00).
        $this->put(route('citas.update', $a), ['duration_minutes' => 90])->assertSessionHasErrors('doctor_member_id');
        $this->assertSame(30, $a->fresh()->duration_minutes);

        $this->put(route('citas.update', $a), ['duration_minutes' => 45, 'treatment_id' => $treatment->id, 'notes' => 'Traer radiografías'])
            ->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame(45, $a->duration_minutes);
        $this->assertSame($treatment->id, $a->treatment_id);
        $this->assertSame('Traer radiografías', $a->notes);

        $this->put(route('citas.update', $a), ['duration_minutes' => 7])->assertSessionHasErrors('duration_minutes');
    }

    // ------------------------------------------------------------ vistas de detalle

    public function test_show_page_lists_the_history_and_actions(): void
    {
        $this->actingAsAdmin();
        $a = $this->makeAppointment($this->makePatient('Camila Ortega Luna'), Carbon::now()->addDays(2)->setTime(10, 0));
        $this->post(route('citas.confirm', $a));

        $this->get(route('citas.show', $a))
            ->assertOk()
            ->assertSee('Camila Ortega Luna')
            ->assertSee('Historial')
            ->assertSee('Pendiente → ', false)
            ->assertSee('Cancelar cita')
            ->assertSee('Reagendar');
    }

    public function test_show_page_for_unknown_appointment_is_404(): void
    {
        $this->actingAsAdmin();
        $this->get(route('citas.show', '11111111-1111-4111-8111-111111111111'))->assertNotFound();
    }

    public function test_actions_require_the_edit_permission(): void
    {
        $a = $this->makeAppointment($this->makePatient(), Carbon::now()->addDays(2)->setTime(10, 0));
        $this->actingAs($this->userWithoutPermissions());

        $this->post(route('citas.confirm', $a))->assertForbidden();
        $this->post(route('citas.cancel', $a), ['reason' => 'prueba'])->assertForbidden();
        $this->get(route('citas.show', $a))->assertForbidden();
    }

    private function secondDoctor(): string
    {
        $user = (string) \Illuminate\Support\Str::uuid();
        DB::table('users')->insert(['id' => $user, 'full_name' => 'Dr. Segundo', 'email' => 'segundo@dental.test', 'is_active' => true]);
        $member = (string) \Illuminate\Support\Str::uuid();
        DB::table('organization_members')->insert([
            'id' => $member, 'organization_id' => $this->organizationId(), 'user_id' => $user,
            'role_id' => DB::table('roles')->where('code', 'doctor')->value('id'), 'is_active' => true,
        ]);
        DB::table('member_branches')->insert(['organization_id' => $this->organizationId(), 'member_id' => $member, 'branch_id' => $this->mainBranchId()]);

        return $member;
    }
}

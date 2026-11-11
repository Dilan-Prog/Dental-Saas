<?php

namespace App\Services\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\ClinicalEncounter;
use App\Models\MedicalRecord;
use App\Models\OutboxEvent;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio de las citas: alta, edición, reprogramación y cambios de
 * estado. Toda escritura va en transacción, deja historial y, en cancelar /
 * confirmar / reprogramar, inserta un evento de outbox en la MISMA transacción.
 */
class AppointmentService
{
    public function __construct(private AgendaLookup $lookup)
    {
    }

    /** @return array{0: Carbon, 1: Carbon} inicio y fin a partir de fecha, hora y minutos. */
    public static function range(string $date, string $time, int $minutes): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, config('app.timezone'));

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    // ------------------------------------------------------------------
    // Alta / edición / reprogramación
    // ------------------------------------------------------------------

    /**
     * @param  array{patient_id:string,doctor_member_id:string,branch_id?:?string,treatment_id:?string,date:string,start_time:string,duration_minutes:int|string,notes?:?string}  $data
     */
    public function create(array $data, User $actor): Appointment
    {
        [$start, $end] = self::range($data['date'], $data['start_time'], (int) $data['duration_minutes']);
        $branchId = $data['branch_id'] ?? $this->lookup->mainBranch()?->id;

        return $this->guard(fn () => DB::transaction(function () use ($data, $actor, $start, $end, $branchId) {
            $this->assertNoConflicts($data['doctor_member_id'], $data['patient_id'], $start, $end);

            $appointment = Appointment::create([
                'branch_id' => $branchId,
                'patient_id' => $data['patient_id'],
                'doctor_member_id' => $data['doctor_member_id'],
                'treatment_id' => $data['treatment_id'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => AppointmentStatus::Pendiente,
                'notes' => $this->cleanNotes($data['notes'] ?? null),
                'created_by' => $actor->id,
            ]);

            $this->history($appointment, null, AppointmentStatus::Pendiente, $actor, null, null, $start);

            return $appointment;
        }));
    }

    /**
     * Edita doctor, tratamiento, duración y notas (no la fecha/hora: eso es reprogramar).
     *
     * @param  array{doctor_member_id?:string,treatment_id?:?string,duration_minutes?:int|string,notes?:?string}  $data
     */
    public function update(Appointment $appointment, array $data, User $actor): Appointment
    {
        return $this->guard(fn () => DB::transaction(function () use ($appointment, $data, $actor) {
            $a = $this->lock($appointment);
            $this->assertEditable($a);

            $doctor = $data['doctor_member_id'] ?? $a->doctor_member_id;
            $minutes = (int) ($data['duration_minutes'] ?? $a->duration_minutes);
            $end = $a->starts_at->copy()->addMinutes($minutes);

            $changedDoctor = $doctor !== $a->doctor_member_id;
            $changedLength = ! $end->equalTo($a->ends_at);

            if ($changedDoctor || $changedLength) {
                $this->assertNoConflicts($doctor, $a->patient_id, $a->starts_at, $end, $a->id);
            }

            $a->doctor_member_id = $doctor;
            $a->ends_at = $end;
            if (array_key_exists('treatment_id', $data)) {
                $a->treatment_id = $data['treatment_id'] ?: null;
            }
            if (array_key_exists('notes', $data)) {
                $a->notes = $this->cleanNotes($data['notes']);
            }
            $a->save();

            if ($changedDoctor || $changedLength) {
                $why = trim(($changedDoctor ? 'Cambio de doctor. ' : '').($changedLength ? 'Cambio de duración ('.$minutes.' min).' : ''));
                $this->history($a, $a->status, $a->status, $actor, $why);
            }

            return $a->refresh();
        }));
    }

    /**
     * @param  array{date:string,start_time:string,duration_minutes?:int|string|null,doctor_member_id?:?string,reason?:?string}  $data
     */
    public function reschedule(Appointment $appointment, array $data, User $actor): Appointment
    {
        return $this->guard(fn () => DB::transaction(function () use ($appointment, $data, $actor) {
            $a = $this->lock($appointment);
            $this->assertEditable($a);

            $minutes = (int) ($data['duration_minutes'] ?? $a->duration_minutes);
            $doctor = $data['doctor_member_id'] ?? $a->doctor_member_id;
            [$start, $end] = self::range($data['date'], $data['start_time'], $minutes);

            if ($start->equalTo($a->starts_at) && $end->equalTo($a->ends_at) && $doctor === $a->doctor_member_id) {
                throw ValidationException::withMessages(['start_time' => 'La nueva fecha y hora son iguales a las actuales.']);
            }

            $this->assertNoConflicts($doctor, $a->patient_id, $start, $end, $a->id, 'start_time', 'start_time');

            $old = $a->starts_at->copy();
            $a->doctor_member_id = $doctor;
            $a->starts_at = $start;
            $a->ends_at = $end;
            $a->save();

            $this->history($a, $a->status, $a->status, $actor, $this->cleanNotes($data['reason'] ?? null), $old, $start);
            $this->outbox($a, 'appointment.rescheduled', ['old_starts_at' => $old->toIso8601String()]);

            return $a->refresh();
        }));
    }

    // ------------------------------------------------------------------
    // Cambios de estado
    // ------------------------------------------------------------------

    public function confirm(Appointment $appointment, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor) {
            $a = $this->transition($appointment, AppointmentStatus::Confirmada, $actor);
            $this->outbox($a, 'appointment.confirmed');

            return $a;
        });
    }

    public function cancel(Appointment $appointment, string $reason, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason, $actor) {
            $a = $this->transition($appointment, AppointmentStatus::Cancelada, $actor, trim($reason));
            $this->outbox($a, 'appointment.cancelled', ['reason' => trim($reason)]);

            return $a;
        });
    }

    public function complete(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, AppointmentStatus::Completada, $actor);
    }

    public function markNoShow(Appointment $appointment, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);
            if ($locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => 'No se puede marcar "No asistió" antes de la hora de la cita.']);
            }

            return $this->transition($locked, AppointmentStatus::NoAsistio, $actor);
        });
    }

    /**
     * Inicia la cita: pasa a "en curso" y crea (o reutiliza) la consulta clínica
     * ligada al expediente del paciente.
     */
    public function start(Appointment $appointment, User $actor): ClinicalEncounter
    {
        return DB::transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);
            if (! $locked->starts_at->isToday()) {
                throw ValidationException::withMessages(['status' => 'Solo se puede iniciar una cita el día en que está programada.']);
            }

            $a = $this->transition($locked, AppointmentStatus::EnCurso, $actor);

            $record = MedicalRecord::query()->firstOrCreate(['patient_id' => $a->patient_id]);

            return ClinicalEncounter::query()->firstOrCreate(
                ['appointment_id' => $a->id],
                [
                    'medical_record_id' => $record->id,
                    'branch_id' => $a->branch_id,
                    'doctor_member_id' => $a->doctor_member_id,
                    'occurred_at' => now(),
                ]
            );
        });
    }

    // ------------------------------------------------------------------
    // Validaciones de dominio
    // ------------------------------------------------------------------

    /**
     * Traslapes del doctor y del paciente con citas vigentes (pendiente,
     * confirmada, en curso). Es la comprobación previa al trigger de la BD.
     */
    public function assertNoConflicts(
        string $doctorId,
        string $patientId,
        Carbon $start,
        Carbon $end,
        ?string $ignoreId = null,
        string $doctorKey = 'doctor_member_id',
        string $patientKey = 'patient_id'
    ): void {
        $base = Appointment::query()->active()
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));

        $errors = [];

        $doctorClash = (clone $base)->where('doctor_member_id', $doctorId)->with('patient')->first();
        if ($doctorClash) {
            $errors[$doctorKey] = 'El doctor ya tiene una cita de '.$doctorClash->starts_at->format('H:i').' a '
                .$doctorClash->ends_at->format('H:i').' ('.($doctorClash->patient?->full_name ?? 'otro paciente').').';
        }

        $patientClash = (clone $base)->where('patient_id', $patientId)->first();
        if ($patientClash) {
            $msg = 'El paciente ya tiene una cita de '.$patientClash->starts_at->format('H:i').' a '.$patientClash->ends_at->format('H:i').' en ese horario.';
            $errors[$patientKey] = isset($errors[$patientKey]) ? $errors[$patientKey].' '.$msg : $msg;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertEditable(Appointment $a): void
    {
        if (! in_array($a->status, [AppointmentStatus::Pendiente, AppointmentStatus::Confirmada], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden modificar citas pendientes o confirmadas (esta cita está "'.$a->status->label().'").',
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function transition(Appointment $appointment, AppointmentStatus $to, User $actor, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $actor, $reason) {
            $a = $this->lock($appointment);

            if (! $a->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => 'La cita está "'.$a->status->label().'" y no puede pasar a "'.$to->label().'".',
                ]);
            }

            $from = $a->status;
            $a->status = $to;
            $a->save();

            $this->history($a, $from, $to, $actor, $reason);

            return $a->refresh();
        });
    }

    private function lock(Appointment $appointment): Appointment
    {
        return Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
    }

    private function history(
        Appointment $a,
        ?AppointmentStatus $from,
        AppointmentStatus $to,
        User $actor,
        ?string $reason = null,
        ?Carbon $oldStart = null,
        ?Carbon $newStart = null
    ): void {
        AppointmentStatusHistory::create([
            'appointment_id' => $a->id,
            'from_status' => $from,
            'to_status' => $to,
            'old_starts_at' => $oldStart,
            'new_starts_at' => $newStart,
            'reason' => $reason,
            'actor_user_id' => $actor->id,
        ]);
    }

    /** Evento de outbox en la misma transacción que el cambio de negocio. */
    private function outbox(Appointment $a, string $event, array $extra = []): void
    {
        $a->loadMissing(['patient', 'doctor.user', 'branch']);

        OutboxEvent::create([
            'event_type' => $event,
            'aggregate_type' => 'appointment',
            'aggregate_id' => $a->id,
            'payload' => array_merge([
                'appointment_id' => $a->id,
                'patient_id' => $a->patient_id,
                'patient_name' => $a->patient?->full_name,
                'patient_phone' => $a->patient?->phone,
                'patient_email' => $a->patient?->email,
                'doctor_member_id' => $a->doctor_member_id,
                'doctor_name' => $a->doctor?->name,
                'branch' => $a->branch?->name,
                'starts_at' => $a->starts_at->toIso8601String(),
                'ends_at' => $a->ends_at->toIso8601String(),
                'status' => $a->status->value,
            ], $extra),
        ]);
    }

    private function cleanNotes(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * Ejecuta el cuerpo y traduce los errores de los triggers/CHECK de la BD
     * (carreras entre dos usuarios) a mensajes de validación claros.
     */
    private function guard(Closure $callback)
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'appointments_no_doctor_overlap')) {
                throw ValidationException::withMessages(['doctor_member_id' => 'El doctor ya tiene una cita en ese horario.']);
            }
            if (str_contains($msg, 'appointments_no_patient_overlap')) {
                throw ValidationException::withMessages(['patient_id' => 'El paciente ya tiene una cita en ese horario.']);
            }
            if (str_contains($msg, 'chk_appointments') || str_contains($msg, 'Check constraint')) {
                throw ValidationException::withMessages(['duration_minutes' => 'La hora de término debe ser posterior a la de inicio.']);
            }

            throw $e;
        }
    }
}

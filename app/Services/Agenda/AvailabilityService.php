<?php

namespace App\Services\Agenda;

use App\Models\Appointment;
use Illuminate\Support\Carbon;

/**
 * Horarios de inicio sugeridos para un doctor en una fecha: respeta el horario
 * de atención, la duración pedida, las citas vigentes del doctor (y del
 * paciente si se indica) y no ofrece horas pasadas del día de hoy.
 */
class AvailabilityService
{
    /**
     * @return list<string> horas "HH:MM"
     */
    public function slots(string $doctorId, Carbon $date, int $duration, ?string $patientId = null, ?string $ignoreAppointmentId = null): array
    {
        $step = (int) config('agenda.step', 15);
        $open = $date->copy()->startOfDay()->addHours((int) config('agenda.hours.start', 8));
        $close = $date->copy()->startOfDay()->addHours((int) config('agenda.hours.end', 21));
        $now = now();

        $busy = Appointment::query()->active()
            ->where('starts_at', '<', $close)->where('ends_at', '>', $open)
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
            ->where(function ($q) use ($doctorId, $patientId) {
                $q->where('doctor_member_id', $doctorId);
                if ($patientId) {
                    $q->orWhere('patient_id', $patientId);
                }
            })
            ->get(['starts_at', 'ends_at']);

        $slots = [];
        for ($t = $open->copy(); $t->copy()->addMinutes($duration)->lessThanOrEqualTo($close); $t->addMinutes($step)) {
            $end = $t->copy()->addMinutes($duration);

            if ($t->lessThan($now)) {
                continue;
            }
            if ($busy->contains(fn ($b) => $b->starts_at->lessThan($end) && $b->ends_at->greaterThan($t))) {
                continue;
            }

            $slots[] = $t->format('H:i');
        }

        return $slots;
    }
}

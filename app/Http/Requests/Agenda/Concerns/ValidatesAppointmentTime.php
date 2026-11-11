<?php

namespace App\Http\Requests\Agenda\Concerns;

use App\Services\Agenda\AppointmentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * Validaciones de fecha/hora comunes a alta y reprogramación de citas:
 * no en el pasado, dentro del horario de atención, mismo día y múltiplos de 5 min.
 */
trait ValidatesAppointmentTime
{
    /**
     * @param  string  $minutesField  campo con la duración (o null para tomarla de $fallbackMinutes)
     */
    protected function validateTimeWindow(Validator $validator, string $dateField, string $timeField, ?int $minutes, string $errorField): void
    {
        if ($validator->errors()->hasAny([$dateField, $timeField, 'duration_minutes']) || $minutes === null) {
            return;
        }

        [$start, $end] = AppointmentService::range((string) $this->input($dateField), (string) $this->input($timeField), $minutes);

        $openHour = (int) config('agenda.hours.start', 8);
        $closeHour = (int) config('agenda.hours.end', 21);
        $open = $start->copy()->startOfDay()->addHours($openHour);
        $close = $start->copy()->startOfDay()->addHours($closeHour);

        if ($start->lessThan(Carbon::now()->startOfMinute())) {
            $validator->errors()->add($errorField, 'No se pueden programar citas en una fecha u hora pasada.');

            return;
        }

        if ($start->lessThan($open) || $end->greaterThan($close)) {
            $validator->errors()->add(
                $errorField,
                sprintf('El horario de atención es de %02d:00 a %02d:00; la cita debe iniciar y terminar dentro de ese horario.', $openHour, $closeHour)
            );

            return;
        }

        if ($start->minute % 5 !== 0) {
            $validator->errors()->add($timeField, 'La hora de inicio debe ser múltiplo de 5 minutos.');
        }
    }
}

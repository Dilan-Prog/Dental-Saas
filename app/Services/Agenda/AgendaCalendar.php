<?php

namespace App\Services\Agenda;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Arma los datos de las vistas día / semana / mes de la agenda:
 * rango, navegación, citas posicionadas en la cuadrícula (con carriles para
 * citas simultáneas de distintos doctores) y el JSON que usa el modal de detalle.
 */
class AgendaCalendar
{
    public const VIEWS = ['day', 'week', 'month'];

    public function __construct(private AgendaLookup $lookup)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $view, Carbon $date, ?string $doctorId, ?string $branchId, string $statusFilter, bool $canEdit): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'week';
        $date = $date->copy()->startOfDay();

        [$rangeStart, $rangeEnd] = $this->range($view, $date);

        $appointments = Appointment::query()
            ->with(['patient', 'treatment', 'doctor.user'])
            ->where('starts_at', '<=', $rangeEnd)->where('ends_at', '>=', $rangeStart)
            ->when($doctorId, fn ($q) => $q->where('doctor_member_id', $doctorId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', $this->statusesFor($statusFilter))
            ->orderBy('starts_at')
            ->get();

        $colors = $this->doctorColors();
        $cards = $appointments->map(fn (Appointment $a) => $this->card($a, $colors, $canEdit));

        $data = [
            'view' => $view,
            'date' => $date,
            'label' => $this->label($view, $date),
            'subtitle' => $this->subtitle($view, $date),
            'prev' => $this->shift($view, $date, -1),
            'next' => $this->shift($view, $date, 1),
            'total' => $appointments->count(),
            'colors' => $colors,
        ];

        if ($view === 'month') {
            return $data + $this->monthGrid($date, $cards);
        }

        return $data + $this->timeGrid($view, $date, $cards);
    }

    /** Payload JSON de una cita (modal de detalle y acciones). */
    public function payloadFor(Appointment $appointment, bool $canEdit): array
    {
        $appointment->loadMissing(['patient', 'treatment', 'doctor.user']);

        return $this->card($appointment, $this->doctorColors(), $canEdit)['payload'];
    }

    /** @return list<string> */
    public function statusesFor(string $filter): array
    {
        if ($filter === 'todas') {
            return AppointmentStatus::values();
        }
        if (in_array($filter, AppointmentStatus::values(), true)) {
            return [$filter];
        }

        // "activas": todo menos canceladas y no asistió.
        return [
            AppointmentStatus::Pendiente->value,
            AppointmentStatus::Confirmada->value,
            AppointmentStatus::EnCurso->value,
            AppointmentStatus::Completada->value,
        ];
    }

    /** @return array<string, string> member_id => dc-N */
    public function doctorColors(): array
    {
        $map = [];
        foreach ($this->lookup->doctors() as $i => $doctor) {
            $map[$doctor->id] = 'dc-'.(($i % 6) + 1);
        }

        return $map;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(string $view, Carbon $date): array
    {
        return match ($view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'month' => [
                $date->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
                $date->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
            ],
            default => [
                $date->copy()->startOfWeek(Carbon::MONDAY),
                $date->copy()->startOfWeek(Carbon::MONDAY)->addDays(6)->endOfDay(),
            ],
        };
    }

    private function shift(string $view, Carbon $date, int $dir): Carbon
    {
        return match ($view) {
            'day' => $date->copy()->addDays($dir),
            'month' => $date->copy()->startOfMonth()->addMonthsNoOverflow($dir),
            default => $date->copy()->addWeeks($dir),
        };
    }

    private function label(string $view, Carbon $date): string
    {
        if ($view === 'day') {
            return $date->translatedFormat('j \d\e F Y');
        }
        if ($view === 'month') {
            return ucfirst($date->translatedFormat('F Y'));
        }

        $from = $date->copy()->startOfWeek(Carbon::MONDAY);
        $to = $from->copy()->addDays(5); // lunes a sábado (como el diseño)

        if ($from->month === $to->month) {
            return $from->day.' - '.$to->day.' '.$to->translatedFormat('F Y');
        }

        return $from->translatedFormat('j M').' - '.$to->translatedFormat('j M Y');
    }

    private function subtitle(string $view, Carbon $date): string
    {
        return match ($view) {
            'day' => ucfirst($date->translatedFormat('l, j \d\e F \d\e Y')),
            'month' => 'Mes de '.$date->translatedFormat('F \d\e Y'),
            default => 'Semana del '.$this->label('week', $date),
        };
    }

    // ------------------------------------------------------------------
    // Cuadrícula horaria (día / semana)
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function timeGrid(string $view, Carbon $date, Collection $cards): array
    {
        $days = [];
        if ($view === 'day') {
            $days = [$date->copy()];
        } else {
            $monday = $date->copy()->startOfWeek(Carbon::MONDAY);
            for ($i = 0; $i < 6; $i++) {
                $days[] = $monday->copy()->addDays($i);
            }
            // El domingo solo aparece si hay citas ese día.
            $sunday = $monday->copy()->addDays(6);
            if ($cards->contains(fn ($c) => $c['date'] === $sunday->toDateString())) {
                $days[] = $sunday;
            }
        }

        $startHour = (int) config('agenda.hours.start', 8);
        $endHour = (int) config('agenda.hours.end', 21);
        foreach ($cards as $c) {
            $startHour = min($startHour, (int) $c['start']->format('G'));
            $endHour = max($endHour, (int) ceil(($c['end']->format('G') * 60 + (int) $c['end']->format('i')) / 60));
        }
        $startHour = max(0, $startHour);
        $endHour = min(24, max($endHour, $startHour + 1));

        $rowH = (int) config('agenda.row_height', 56);
        $columns = [];
        foreach ($days as $day) {
            $key = $day->toDateString();
            $dayCards = $cards->filter(fn ($c) => $c['date'] === $key)->values()->all();

            $columns[] = [
                'date' => $day,
                'key' => $key,
                'is_today' => $day->isToday(),
                'is_weekend' => $day->isWeekend(),
                'cards' => $this->layout($dayCards, $startHour, $rowH),
                'now_top' => $day->isToday() && now()->hour >= $startHour && now()->hour < $endHour
                    ? round(((now()->hour * 60 + now()->minute) - $startHour * 60) * $rowH / 60, 1)
                    : null,
            ];
        }

        return [
            'columns' => $columns,
            'hours' => range($startHour, $endHour - 1),
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'row_height' => $rowH,
            'grid_height' => ($endHour - $startHour) * $rowH,
        ];
    }

    /**
     * Posiciona las citas de un día. Las simultáneas se reparten en carriles
     * (columnas) dentro de su grupo de traslape.
     *
     * @param  list<array<string, mixed>>  $cards  ordenadas por inicio
     * @return list<array<string, mixed>>
     */
    private function layout(array $cards, int $startHour, int $rowH): array
    {
        $out = [];
        $cluster = [];
        $clusterEnd = null;

        $flush = function () use (&$cluster, &$out) {
            $lanes = $cluster ? max(array_column($cluster, 'lane')) + 1 : 1;
            foreach ($cluster as $c) {
                $c['lanes'] = $lanes;
                $out[] = $c;
            }
            $cluster = [];
        };

        $laneEnds = [];
        foreach ($cards as $c) {
            if ($clusterEnd !== null && $c['start']->greaterThanOrEqualTo($clusterEnd)) {
                $flush();
                $laneEnds = [];
                $clusterEnd = null;
            }

            $lane = null;
            foreach ($laneEnds as $i => $end) {
                if ($end->lessThanOrEqualTo($c['start'])) {
                    $lane = $i;
                    break;
                }
            }
            if ($lane === null) {
                $lane = count($laneEnds);
            }
            $laneEnds[$lane] = $c['end'];
            $clusterEnd = $clusterEnd === null || $c['end']->greaterThan($clusterEnd) ? $c['end'] : $clusterEnd;

            $minutes = ($c['start']->hour * 60 + $c['start']->minute) - $startHour * 60;
            $c['top'] = round(max(0, $minutes) * $rowH / 60, 1);
            $c['height'] = round(max($c['minutes'] * $rowH / 60, 30), 1);
            $c['lane'] = $lane;
            $cluster[] = $c;
        }
        $flush();

        return $out;
    }

    // ------------------------------------------------------------------
    // Vista de mes
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function monthGrid(Carbon $date, Collection $cards): array
    {
        [$from, $to] = $this->range('month', $date);
        $byDay = $cards->groupBy('date');

        $weeks = [];
        for ($d = $from->copy(); $d->lessThanOrEqualTo($to); $d->addDay()) {
            $weekIndex = (int) floor($d->diffInDays($from) / 7);
            $list = $byDay->get($d->toDateString(), collect());

            $weeks[$weekIndex][] = [
                'date' => $d->copy(),
                'key' => $d->toDateString(),
                'in_month' => $d->month === $date->month,
                'is_today' => $d->isToday(),
                'count' => $list->count(),
                'items' => $list->take(3)->values()->all(),
                'more' => max(0, $list->count() - 3),
            ];
        }

        return ['weeks' => array_values($weeks)];
    }

    // ------------------------------------------------------------------
    // Tarjeta de cita + payload del modal
    // ------------------------------------------------------------------

    /**
     * @param  array<string, string>  $colors
     * @return array<string, mixed>
     */
    private function card(Appointment $a, array $colors, bool $canEdit): array
    {
        $status = $a->status;
        $editable = in_array($status, [AppointmentStatus::Pendiente, AppointmentStatus::Confirmada], true);
        $patient = $a->patient?->full_name ?? 'Paciente';
        $doctor = $a->doctor?->name ?? 'Doctor';

        $can = [
            'confirm' => $canEdit && $status === AppointmentStatus::Pendiente,
            'start' => $canEdit && $editable && $a->starts_at->isToday(),
            'complete' => $canEdit && $status === AppointmentStatus::EnCurso,
            'noshow' => $canEdit && $editable && $a->starts_at->isPast(),
            'cancel' => $canEdit && $a->canTransitionTo(AppointmentStatus::Cancelada),
            'reschedule' => $canEdit && $editable,
        ];

        return [
            'id' => $a->id,
            'date' => $a->starts_at->toDateString(),
            'start' => $a->starts_at,
            'end' => $a->ends_at,
            'minutes' => $a->duration_minutes,
            'time' => $a->starts_at->format('G:i'),
            'patient' => $patient,
            'treatment' => $a->treatment?->name,
            'doctor' => $doctor,
            'doctor_id' => $a->doctor_member_id,
            'color' => $colors[$a->doctor_member_id] ?? 'dc-0',
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'payload' => [
                'id' => $a->id,
                'patient' => $patient,
                'folio' => $a->patient?->folio,
                'treatment' => $a->treatment?->name ?? 'Sin tratamiento',
                'doctor' => $doctor,
                'date_label' => $a->starts_at->translatedFormat('j M Y').' · '.$a->starts_at->format('H:i'),
                'range_label' => $a->starts_at->format('H:i').' – '.$a->ends_at->format('H:i'),
                'time' => $a->starts_at->format('H:i'),
                'duration' => $a->duration_minutes,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_color' => $status->color(),
                'notes' => $a->notes,
                'can' => $can,
                'urls' => [
                    'show' => route('citas.show', $a->id),
                    'cancel' => route('citas.cancel', $a->id),
                    'confirm' => route('citas.confirm', $a->id),
                    'start' => route('citas.start', $a->id),
                    'complete' => route('citas.complete', $a->id),
                    'noshow' => route('citas.noshow', $a->id),
                    'reschedule' => route('citas.reschedule', $a->id),
                ],
                'reschedule' => [
                    'date' => $a->starts_at->toDateString(),
                    'start_time' => $a->starts_at->format('H:i'),
                    'duration_minutes' => $a->duration_minutes,
                    'doctor_member_id' => $a->doctor_member_id,
                ],
            ],
        ];
    }
}

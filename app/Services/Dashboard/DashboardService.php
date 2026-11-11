<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Datos del tablero principal. Toda la informacion se calcula con consultas
 * agregadas sobre los modelos; los doctores solo ven su propia agenda.
 */
class DashboardService
{
    /** Altura en pixeles de una hora en la linea de tiempo. */
    public const HOUR_PX = 40;

    private ?string $doctorMemberId = null;

    public function __construct(private User $user)
    {
        $member = $user->member;
        if ($member && $member->isDoctor() && ! $user->isAdmin()) {
            $this->doctorMemberId = (string) $member->id;
        }
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    /** Citas visibles para el usuario (un doctor solo ve las suyas). */
    private function appointments(): Builder
    {
        return Appointment::query()
            ->when($this->doctorMemberId, fn (Builder $q) => $q->where('doctor_member_id', $this->doctorMemberId));
    }

    public function greeting(): array
    {
        $hour = (int) now()->format('G');
        $salute = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
        $first = trim((string) strtok((string) $this->user->full_name, ' '));
        $branch = Branch::query()->where('is_main', true)->value('name')
            ?? Branch::query()->orderBy('name')->value('name');
        $date = ucfirst(now()->translatedFormat('l, j \d\e F \d\e Y'));

        return [
            'title' => $salute.($first !== '' ? ', '.$first : ''),
            'subtitle' => $date.($branch ? ' · '.$branch : ''),
        ];
    }

    /**
     * Tarjetas de indicadores. Las financieras solo se muestran con permiso.
     *
     * @return array<int, array{label:string,value:string,delta:?string,trend:?string}>
     */
    public function kpis(): array
    {
        $user = $this->user;
        $kpis = [];

        // Citas de hoy vs ayer (sin canceladas).
        if ($user->hasPermission('agenda', 'ver')) {
            $count = fn (Carbon $day) => $this->appointments()
                ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->where('status', '!=', AppointmentStatus::Cancelada->value)
                ->count();
            $today = $count(now());
            $yesterday = $count(now()->subDay());
            $kpis[] = [
                'label' => 'Citas hoy',
                'value' => (string) $today,
                'delta' => $this->signed($today - $yesterday).' vs. ayer ('.$yesterday.')',
                'trend' => $this->trend($today - $yesterday),
            ];
        }

        // Ingresos del dia vs ayer (pagos registrados, sin revertidos).
        if ($user->hasPermission('finanzas', 'ver') || $user->hasPermission('facturacion', 'ver')) {
            $income = fn (Carbon $day) => (string) Payment::query()
                ->where('status', 'registrado')
                ->whereBetween('paid_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->sum('amount');
            $today = Money::round($income(now()));
            $yesterday = Money::round($income(now()->subDay()));
            if (Money::cmp($yesterday, '0') === 0) {
                $delta = Money::cmp($today, '0') > 0 ? 'Sin ingresos ayer' : 'Sin ingresos registrados';
                $trend = Money::cmp($today, '0') > 0 ? 'up' : null;
            } else {
                $pct = ((float) $today - (float) $yesterday) / (float) $yesterday * 100;
                $delta = $this->signed((int) round($pct)).'% vs. ayer';
                $trend = $this->trend($pct);
            }
            $kpis[] = [
                'label' => 'Ingresos del día',
                'value' => Money::format($today),
                'delta' => $delta,
                'trend' => $trend,
            ];
        }

        // Pacientes nuevos del mes vs mes anterior.
        if ($user->hasPermission('pacientes', 'ver')) {
            $new = fn (Carbon $m) => Patient::query()
                ->whereBetween('created_at', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])
                ->count();
            $thisMonth = $new(now());
            $lastMonth = $new(now()->subMonthNoOverflow());
            $kpis[] = [
                'label' => 'Pacientes nuevos (mes)',
                'value' => (string) $thisMonth,
                'delta' => $this->signed($thisMonth - $lastMonth).' vs. mes anterior ('.$lastMonth.')',
                'trend' => $this->trend($thisMonth - $lastMonth),
            ];
        }

        // Facturas por cobrar: saldo = total - aplicaciones vigentes.
        if ($user->hasPermission('facturacion', 'ver')) {
            $pending = $this->receivable();
            $kpis[] = [
                'label' => 'Facturas por cobrar',
                'value' => Money::format($pending['total']),
                'delta' => $pending['count'] === 1 ? '1 factura pendiente' : $pending['count'].' facturas pendientes',
                'trend' => null,
            ];
        }

        return $kpis;
    }

    /**
     * Facturas con saldo pendiente.
     *
     * @return array{count:int,total:string,items:Collection}
     */
    public function receivable(): array
    {
        $items = Invoice::receivable()
            ->withSum(['applications as applied_sum' => fn ($q) => $q->whereNull('reversed_at')], 'applied_amount')
            ->with('patient:id,full_name')
            ->orderBy('issued_at')
            ->get()
            ->map(function (Invoice $inv) {
                $inv->balance_due = Money::sub($inv->total, $inv->applied_sum ?? 0);

                return $inv;
            })
            ->filter(fn (Invoice $inv) => Money::cmp($inv->balance_due, '0') > 0)
            ->values();

        return [
            'count' => $items->count(),
            'total' => Money::sum($items->pluck('balance_due')),
            'items' => $items,
        ];
    }

    /**
     * Citas de hoy con carril y posicion para dibujar la linea de tiempo.
     *
     * @return array{events:array<int,array<string,mixed>>,start_hour:int,end_hour:int,count:int,hour_px:int}
     */
    public function todayAgenda(): array
    {
        $appts = $this->appointments()
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->where('status', '!=', AppointmentStatus::Cancelada->value)
            ->with(['patient:id,full_name', 'doctor.user:id,full_name', 'treatment:id,name'])
            ->orderBy('starts_at')
            ->get();

        $events = $appts->map(function (Appointment $a) {
            $status = $a->status instanceof AppointmentStatus ? $a->status : AppointmentStatus::from((string) $a->status);

            return [
                'id' => $a->id,
                'start' => $a->starts_at->copy(),
                'end' => $a->ends_at->copy(),
                'patient' => $a->patient?->full_name ?? 'Paciente',
                'treatment' => $a->treatment?->name,
                'doctor' => $a->doctor?->user?->full_name,
                'status' => $status->label(),
                'variant' => $this->variant($status),
                'lane' => 0,
                'lanes' => 1,
            ];
        })->values()->all();

        // Carriles para citas empalmadas (distintos doctores).
        $laneEnds = [];
        $clusterStart = 0;
        foreach ($events as $i => &$e) {
            $busy = array_filter($laneEnds, fn (Carbon $end) => $end->gt($e['start']));
            if (! $busy) {
                $this->closeCluster($events, $clusterStart, $i, count($laneEnds));
                $clusterStart = $i;
                $laneEnds = [];
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane]->gt($e['start'])) {
                $lane++;
            }
            $laneEnds[$lane] = $e['end'];
            $e['lane'] = $lane;
        }
        unset($e);
        $this->closeCluster($events, $clusterStart, count($events), count($laneEnds));

        $startHour = 8;
        $endHour = 20;
        foreach ($events as $e) {
            $startHour = min($startHour, (int) $e['start']->format('G'));
            $endHour = max($endHour, (int) ceil(($e['end']->hour * 60 + $e['end']->minute) / 60));
        }
        $endHour = min(24, $endHour);

        foreach ($events as &$e) {
            $minutes = $e['start']->hour * 60 + $e['start']->minute - $startHour * 60;
            $duration = max(15, $e['start']->diffInMinutes($e['end']));
            $e['top'] = round($minutes / 60 * self::HOUR_PX, 1);
            $e['height'] = max(34, round($duration / 60 * self::HOUR_PX, 1) - 2);
            $e['range'] = $e['start']->format('H:i').' – '.$e['end']->format('H:i');
        }
        unset($e);

        return [
            'events' => $events,
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'count' => count($events),
            'hour_px' => self::HOUR_PX,
        ];
    }

    private function closeCluster(array &$events, int $from, int $to, int $lanes): void
    {
        for ($k = $from; $k < $to; $k++) {
            $events[$k]['lanes'] = max(1, $lanes);
        }
    }

    /** Proximas citas (pendientes o confirmadas) a partir de este momento. */
    public function upcoming(int $limit = 5): Collection
    {
        return $this->appointments()
            ->where('starts_at', '>=', now())
            ->whereIn('status', [AppointmentStatus::Pendiente->value, AppointmentStatus::Confirmada->value])
            ->with(['patient:id,full_name', 'treatment:id,name'])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (Appointment $a) => [
                'when' => ucfirst($a->starts_at->translatedFormat('D G:i')),
                'patient' => $a->patient?->full_name ?? 'Paciente',
                'treatment' => $a->treatment?->name,
            ]);
    }

    /**
     * Ingresos diarios de los ultimos $days dias.
     *
     * @return array{days:array<int,array<string,mixed>>,total:string,delta:?string,max:float}
     */
    public function incomeSeries(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = Payment::query()
            ->where('status', 'registrado')
            ->whereBetween('paid_at', [$from, now()->endOfDay()])
            ->selectRaw('DATE(paid_at) as d, SUM(amount) as t')
            ->groupBy('d')
            ->pluck('t', 'd');

        $series = [];
        $total = '0.00';
        $max = 0.0;
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i);
            $amount = Money::round($rows[$day->toDateString()] ?? 0);
            $total = Money::add($total, $amount);
            $max = max($max, (float) $amount);
            $series[] = [
                'date' => $day,
                'amount' => $amount,
                'label' => $day->translatedFormat('j M').': '.Money::format($amount),
            ];
        }

        $prevFrom = $from->copy()->subDays($days);
        $previous = Money::round((string) Payment::query()
            ->where('status', 'registrado')
            ->whereBetween('paid_at', [$prevFrom, $from->copy()->subSecond()])
            ->sum('amount'));
        $delta = null;
        if (Money::cmp($previous, '0') > 0) {
            $pct = ((float) $total - (float) $previous) / (float) $previous * 100;
            $delta = ($pct >= 0 ? '+' : '').number_format($pct, 1).'%';
        }

        return ['days' => $series, 'total' => $total, 'delta' => $delta, 'max' => $max];
    }

    /**
     * Pendientes y alertas ordenados por urgencia (maximo $limit).
     *
     * @return array<int, array{badge:string,variant:string,text:string,url:?string}>
     */
    public function alerts(int $limit = 8): array
    {
        $user = $this->user;
        $out = [];

        if ($user->hasPermission('presupuestos', 'ver')) {
            $quotes = Quote::query()
                ->where('status', 'enviado')
                ->where('sent_at', '<=', now()->subDays(7))
                ->orderBy('sent_at')
                ->limit(3)
                ->get(['id', 'folio', 'sent_at']);
            foreach ($quotes as $q) {
                $out[] = [
                    'badge' => 'Urgente', 'variant' => 'danger',
                    'text' => 'Presupuesto '.$q->folio.' sin respuesta',
                    'url' => $this->url('presupuestos.show', $q),
                ];
            }
        }

        if ($user->hasPermission('inventario', 'ver')) {
            $stocks = InventoryStock::query()->low()->with('item:id,name')->limit(3)->get();
            foreach ($stocks as $s) {
                $out[] = [
                    'badge' => 'Stock bajo', 'variant' => 'warning',
                    'text' => ($s->item?->name ?? 'Insumo').' por debajo del mínimo',
                    'url' => $this->url('inventario.index'),
                ];
            }
        }

        if ($user->hasPermission('facturacion', 'ver')) {
            foreach ($this->receivable()['items']->take(3) as $inv) {
                $out[] = [
                    'badge' => 'Pendiente', 'variant' => 'info',
                    'text' => 'Factura pendiente de cobro · '.Money::format($inv->balance_due),
                    'url' => $this->url('facturacion.show', $inv),
                ];
            }
        }

        if ($user->hasPermission('agenda', 'ver')) {
            $pending = $this->appointments()
                ->where('status', AppointmentStatus::Pendiente->value)
                ->where('starts_at', '>=', now())
                ->with('patient:id,full_name')
                ->orderBy('starts_at')
                ->limit(3)
                ->get();
            foreach ($pending as $a) {
                $out[] = [
                    'badge' => 'Pendiente', 'variant' => 'info',
                    'text' => 'Confirmar cita de '.($a->patient?->full_name ?? 'paciente'),
                    'url' => $this->url('agenda.index', ['date' => $a->starts_at->toDateString()]),
                ];
            }
        }

        return array_slice($out, 0, $limit);
    }

    private function url(string $route, mixed $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }

    private function variant(AppointmentStatus $status): string
    {
        $color = $status->color();

        return $color === 'muted' ? 'neutral' : $color;
    }

    private function signed(int|float $n): string
    {
        return ($n > 0 ? '+' : '').$n;
    }

    private function trend(int|float $n): ?string
    {
        return $n > 0 ? 'up' : ($n < 0 ? 'down' : null);
    }
}

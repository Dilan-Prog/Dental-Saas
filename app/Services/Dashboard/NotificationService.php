<?php

namespace App\Services\Dashboard;

use App\Models\AppNotification;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\OrganizationMember;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Notificaciones en la aplicacion (canal in_app).
 *
 * Las alertas se derivan del estado real del sistema (stock bajo, pagos,
 * citas, presupuestos) y se registran una sola vez gracias a dedup_key
 * (unico por organizacion). Cada alerta crea un destinatario por usuario
 * con permiso de ver el modulo correspondiente.
 */
class NotificationService
{
    /** Minutos entre sincronizaciones de alertas. */
    public const SYNC_TTL = 300;

    /** @var array<string,int> */
    private static array $unreadMemo = [];

    /** @var array<string,bool> */
    private array $disabledCache = [];

    private ?Collection $members = null;

    /** Consulta base: notificaciones in_app ya programadas para el usuario. */
    public static function forUser(User $user): Builder
    {
        return NotificationRecipient::query()
            ->where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->where('scheduled_for', '<=', now());
    }

    public static function unreadCount(User $user): int
    {
        return self::$unreadMemo[$user->id] ??= (int) self::forUser($user)->whereNull('read_at')->count();
    }

    public static function recent(User $user, int $limit = 8): Collection
    {
        return self::forUser($user)
            ->with('notification')
            ->orderByDesc('scheduled_for')
            ->limit($limit)
            ->get()
            ->filter(fn (NotificationRecipient $r) => $r->notification !== null)
            ->values();
    }

    public static function markRead(NotificationRecipient $recipient): void
    {
        $recipient->markAsRead();
        self::$unreadMemo = [];
    }

    public static function markAllRead(User $user): int
    {
        self::$unreadMemo = [];

        return self::forUser($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Variante de color del punto segun el tipo de evento. */
    public static function kind(?string $eventType): string
    {
        $e = (string) $eventType;

        return match (true) {
            str_starts_with($e, 'low_stock') => 'warning',
            str_starts_with($e, 'payment') => 'success',
            str_starts_with($e, 'quote'), str_starts_with($e, 'invoice') => 'danger',
            default => 'primary',
        };
    }

    /** Enlace al registro relacionado (si la ruta del modulo existe). */
    public static function link(AppNotification $n): ?string
    {
        $id = $n->entity_id;

        return match ($n->entity_type) {
            'appointment' => Route::has('agenda.index') ? route('agenda.index') : null,
            'patient' => $id && Route::has('pacientes.show') ? route('pacientes.show', $id) : null,
            'quote' => $id && Route::has('presupuestos.show') ? route('presupuestos.show', $id) : null,
            'invoice' => $id && Route::has('facturacion.show') ? route('facturacion.show', $id) : null,
            'payment' => Route::has('facturacion.index') ? route('facturacion.index') : null,
            'inventory_item' => Route::has('inventario.index') ? route('inventario.index') : null,
            default => null,
        };
    }

    /**
     * Genera las notificaciones pendientes. Limitado por cache para no
     * recalcular en cada pagina; $force lo salta (pruebas, comandos).
     */
    public static function sync(bool $force = false): void
    {
        $org = Organization::currentId();
        if (! $org) {
            return;
        }
        if (! $force && ! Cache::add('dashboard:notif-sync:'.$org, 1, self::SYNC_TTL)) {
            return;
        }

        (new self)->generate();
    }

    public function generate(): void
    {
        $this->lowStock();
        $this->confirmedAppointments();
        $this->unconfirmedAppointments();
        $this->payments();
        $this->quotes();
        $this->invoices();
    }

    private function lowStock(): void
    {
        $week = now()->format('o-\WW');
        InventoryStock::query()->low()->with(['item:id,name,unit_of_measure', 'branch:id,name'])->get()
            ->each(function (InventoryStock $s) use ($week) {
                $name = $s->item?->name ?? 'Insumo';
                $this->notify(
                    'low_stock', 'Stock bajo: '.$name,
                    'Existencia '.rtrim(rtrim((string) $s->quantity_on_hand, '0'), '.').' '.($s->item?->unit_of_measure ?? '')
                        .' (mínimo '.rtrim(rtrim((string) $s->min_quantity, '0'), '.').')',
                    'inventory_item', $s->item_id,
                    "low_stock:{$s->item_id}:{$s->branch_id}:{$week}", 'inventario', now()
                );
            });
    }

    private function confirmedAppointments(): void
    {
        AppointmentStatusHistory::query()
            ->where('to_status', 'confirmada')
            ->where('created_at', '>=', now()->subDay())
            ->with('appointment.patient:id,full_name')
            ->get()
            ->each(function (AppointmentStatusHistory $h) {
                $a = $h->appointment;
                if (! $a) {
                    return;
                }
                $this->notify(
                    'appointment_confirmed', 'Nueva cita confirmada',
                    ($a->patient?->full_name ?? 'Paciente').' · '.$a->starts_at->translatedFormat('D j M, H:i'),
                    'appointment', $a->id, 'appointment_confirmed:'.$h->id, 'agenda', $h->created_at
                );
            });
    }

    private function unconfirmedAppointments(): void
    {
        Appointment::query()
            ->where('status', 'pendiente')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->with('patient:id,full_name')
            ->get()
            ->each(function (Appointment $a) {
                $this->notify(
                    'appointment_unconfirmed', 'Confirmar cita de '.($a->patient?->full_name ?? 'paciente'),
                    'Cita '.$a->starts_at->translatedFormat('D j M, H:i').' sin confirmar',
                    'appointment', $a->id, 'appointment_unconfirmed:'.$a->id, 'agenda', now()
                );
            });
    }

    private function payments(): void
    {
        Payment::query()
            ->where('status', 'registrado')
            ->where('paid_at', '>=', now()->startOfDay())
            ->with('patient:id,full_name')
            ->get()
            ->each(function (Payment $p) {
                $this->notify(
                    'payment_received', 'Pago recibido · '.Money::format($p->amount),
                    $p->patient?->full_name, 'payment', $p->id, 'payment_received:'.$p->id, 'facturacion', $p->paid_at
                );
            });
    }

    private function quotes(): void
    {
        Quote::query()
            ->where('status', 'enviado')
            ->where('sent_at', '<=', now()->subDays(7))
            ->get(['id', 'folio', 'sent_at'])
            ->each(function (Quote $q) {
                $this->notify(
                    'quote_unanswered', 'Presupuesto '.$q->folio.' sin respuesta',
                    'Enviado el '.$q->sent_at->translatedFormat('j M Y'),
                    'quote', $q->id, 'quote_unanswered:'.$q->id, 'presupuestos', now()
                );
            });
    }

    private function invoices(): void
    {
        $month = now()->format('Y-m');
        Invoice::receivable()
            ->where('issued_at', '<=', now()->subDays(30))
            ->withSum(['applications as applied_sum' => fn ($q) => $q->whereNull('reversed_at')], 'applied_amount')
            ->get()
            ->each(function (Invoice $inv) use ($month) {
                $due = Money::sub($inv->total, $inv->applied_sum ?? 0);
                if (Money::cmp($due, '0') <= 0) {
                    return;
                }
                $this->notify(
                    'invoice_receivable', 'Factura '.$inv->internal_folio.' pendiente de cobro · '.Money::format($due),
                    'Emitida hace más de 30 días', 'invoice', $inv->id,
                    "invoice_receivable:{$inv->id}:{$month}", 'facturacion', now()
                );
            });
    }

    /**
     * Crea la notificacion (una vez por dedup_key) y sus destinatarios.
     */
    private function notify(string $event, string $title, ?string $body, ?string $entityType, ?string $entityId, string $dedup, string $module, $at): void
    {
        if ($this->disabled($event)) {
            return;
        }

        $n = AppNotification::query()->firstOrCreate(
            ['dedup_key' => $dedup],
            [
                'event_type' => $event,
                'title' => mb_substr($title, 0, 200),
                'body' => $body,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'created_at' => $at ?? now(),
            ]
        );

        if (! $n->wasRecentlyCreated) {
            return;
        }

        foreach ($this->recipients($module) as $userId) {
            NotificationRecipient::query()->create([
                'notification_id' => $n->id,
                'user_id' => $userId,
                'channel' => 'in_app',
                'scheduled_for' => now(),
            ]);
        }
    }

    /** Respeta notification_preferences: un evento desactivado para in_app no se genera. */
    private function disabled(string $event): bool
    {
        if (! array_key_exists($event, $this->disabledCache)) {
            $pref = NotificationPreference::query()->where('event_type', $event)->where('channel', 'in_app')->first();
            $this->disabledCache[$event] = $pref && ! $pref->is_enabled;
        }

        return $this->disabledCache[$event];
    }

    /** @return array<int,string> ids de usuarios activos con permiso de ver el modulo */
    private function recipients(string $module): array
    {
        $this->members ??= OrganizationMember::query()->active()->with('user')->get();

        return $this->members
            ->map->user
            ->filter(fn (?User $u) => $u && $u->is_active && $u->hasPermission($module, 'ver'))
            ->pluck('id')
            ->all();
    }
}

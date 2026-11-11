<?php

namespace App\Services\Finanzas;

use App\Enums\CashSessionStatus;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cajas y turnos (cash_sessions). Reglas:
 *  - una sola sesion abierta por caja (unique en BD + validacion previa);
 *  - el efectivo esperado lo calcula SIEMPRE el servidor (CashSession::expectedCash);
 *  - una sesion cerrada es inmutable (trigger); las correcciones son ajustes con motivo.
 */
class CashSessionService
{
    public const MAX_AMOUNT = '99999999999.99';

    /** Crea la "Caja principal" de la sucursal principal si aun no existe ninguna caja. */
    public function ensureDefaultRegister(): void
    {
        if (CashRegister::query()->exists()) {
            return;
        }

        $branch = Branch::query()->where('is_main', true)->first() ?? Branch::query()->first();
        if (! $branch) {
            return;
        }

        CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Caja principal',
            'status' => RecordStatus::Active->value,
        ]);
    }

    /** @return Collection<int, CashSession> */
    public function openSessions(?string $branchId = null): Collection
    {
        $query = CashSession::query()->open()->with('cashRegister.branch')->orderBy('opened_at');

        if ($branchId) {
            $query->whereHas('cashRegister', fn ($r) => $r->where('branch_id', $branchId));
        }

        return $query->get();
    }

    /** Efectivo esperado total en las cajas abiertas (para el KPI "En caja hoy"). */
    public function cashOnHand(): array
    {
        $sessions = $this->openSessions();
        $total = '0.00';
        foreach ($sessions as $session) {
            $total = Money::add($total, $session->expectedCash());
        }

        return ['amount' => $total, 'sessions' => $sessions];
    }

    /**
     * Resuelve (y bloquea) la sesion abierta donde caera un movimiento en efectivo.
     * Debe llamarse dentro de una transaccion.
     */
    public function sessionForCashMovement(?string $branchId, ?string $sessionId, string $bag = 'movement'): CashSession
    {
        $sessions = $this->openSessions($branchId);

        if ($sessionId) {
            $picked = $sessions->firstWhere('id', $sessionId);
            if (! $picked) {
                $this->fail('cash_session_id', 'La caja seleccionada no esta abierta en esta sucursal.', $bag);
            }
        } elseif ($sessions->count() === 1) {
            $picked = $sessions->first();
        } elseif ($sessions->isEmpty()) {
            $this->fail('method', 'No hay una caja abierta en esta sucursal. Abre un turno de caja para registrar movimientos en efectivo.', $bag);
        } else {
            $this->fail('cash_session_id', 'Hay varias cajas abiertas: selecciona en cual registrar el movimiento.', $bag);
        }

        $locked = CashSession::query()->lockForUpdate()->find($picked->id);
        if (! $locked || $locked->status !== CashSessionStatus::Abierta) {
            $this->fail('cash_session_id', 'La caja ya fue cerrada. Abre un nuevo turno.', $bag);
        }

        return $locked;
    }

    public function open(CashRegister $register, string $openingAmount, User $user): CashSession
    {
        $bag = 'openCash';

        if ($register->status !== RecordStatus::Active) {
            $this->fail('cash_register_id', 'La caja esta inactiva y no se puede abrir.', $bag);
        }

        return DB::transaction(function () use ($register, $openingAmount, $user, $bag) {
            $locked = CashRegister::query()->lockForUpdate()->findOrFail($register->id);

            if (CashSession::query()->open()->where('cash_register_id', $locked->id)->exists()) {
                $this->fail('cash_register_id', 'Esta caja ya tiene un turno abierto.', $bag);
            }

            try {
                return CashSession::create([
                    'cash_register_id' => $locked->id,
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                    'opening_amount' => Money::round($openingAmount),
                    'status' => CashSessionStatus::Abierta->value,
                ]);
            } catch (QueryException $e) {
                $this->failFromDatabase($e, 'cash_register_id', $bag);
            }
        });
    }

    public function close(CashSession $session, string $countedCash, ?string $notes, User $user): CashSession
    {
        $bag = 'closeCash';

        return DB::transaction(function () use ($session, $countedCash, $notes, $user, $bag) {
            $locked = CashSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== CashSessionStatus::Abierta) {
                $this->fail('counted_cash', 'Este turno ya fue cerrado.', $bag);
            }

            $expected = $locked->expectedCash();
            $counted = Money::round($countedCash);
            $difference = Money::sub($counted, $expected);

            if (Money::cmp($difference, '0') !== 0 && blank($notes)) {
                $this->fail('notes', 'Hay una diferencia de '.Money::format($difference).' en el corte: explica el motivo en las notas.', $bag);
            }

            try {
                $locked->update([
                    'status' => CashSessionStatus::Cerrada->value,
                    'closed_by' => $user->id,
                    'closed_at' => now(),
                    'expected_cash' => $expected,
                    'counted_cash' => $counted,
                    'difference' => $difference,
                    'notes' => filled($notes) ? trim($notes) : null,
                ]);
            } catch (QueryException $e) {
                $this->failFromDatabase($e, 'counted_cash', $bag);
            }

            return $locked->refresh();
        });
    }

    /** Ajuste firmado sobre un turno YA cerrado (la sesion cerrada no se edita). */
    public function adjust(CashSession $session, string $amount, string $reason, User $user): CashSessionAdjustment
    {
        $bag = 'adjust';

        return DB::transaction(function () use ($session, $amount, $reason, $user, $bag) {
            $locked = CashSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== CashSessionStatus::Cerrada) {
                $this->fail('amount', 'Los ajustes solo aplican a turnos cerrados; en un turno abierto registra el movimiento.', $bag);
            }

            return CashSessionAdjustment::create([
                'cash_session_id' => $locked->id,
                'amount' => Money::round($amount),
                'reason' => trim($reason),
                'created_by' => $user->id,
            ]);
        });
    }

    /** Diferencia final del turno = contado - (esperado + ajustes). */
    public function adjustedDifference(CashSession $session): ?string
    {
        if ($session->status !== CashSessionStatus::Cerrada || $session->counted_cash === null) {
            return null;
        }

        $expected = Money::add($session->expected_cash, (string) $session->adjustments()->sum('amount'));

        return Money::sub($session->counted_cash, $expected);
    }

    private function fail(string $field, string $message, string $bag): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag($bag);
    }

    private function failFromDatabase(QueryException $e, string $field, string $bag): never
    {
        $message = (string) $e->getMessage();

        if (str_contains($message, 'uq_cash_one_open') || str_contains($message, '1062')) {
            $this->fail($field, 'Esta caja ya tiene un turno abierto.', $bag);
        }
        if (str_contains($message, 'Sesion de caja cerrada')) {
            $this->fail($field, 'El turno ya esta cerrado y no se puede modificar; usa un ajuste.', $bag);
        }

        throw $e;
    }
}

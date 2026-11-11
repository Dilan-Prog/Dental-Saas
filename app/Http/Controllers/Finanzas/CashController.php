<?php

namespace App\Http\Controllers\Finanzas;

use App\Enums\CashSessionStatus;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finanzas\CloseCashSessionRequest;
use App\Http\Requests\Finanzas\OpenCashSessionRequest;
use App\Http\Requests\Finanzas\SaveCashRegisterRequest;
use App\Http\Requests\Finanzas\StoreCashAdjustmentRequest;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\Finanzas\CashSessionService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** Cajas (CashRegister) y turnos (CashSession): abrir, cortar, ajustar y consultar. */
class CashController extends Controller
{
    public function __construct(private readonly CashSessionService $cash)
    {
    }

    public function index(): View
    {
        $this->cash->ensureDefaultRegister();

        $registers = CashRegister::query()->with('branch')->orderBy('name')->get();
        $openByRegister = $this->cash->openSessions()->keyBy('cash_register_id');

        $history = CashSession::query()
            ->with(['cashRegister.branch', 'opener', 'closer'])
            ->withSum('adjustments as adjustments_total', 'amount')
            ->orderByDesc('opened_at')
            ->paginate(10);

        return view('finanzas.cajas', [
            'registers' => $registers,
            'openByRegister' => $openByRegister,
            'history' => $history,
            'branches' => Branch::query()->where('status', 'active')->orderByDesc('is_main')->orderBy('name')->get(),
            'cash' => $this->cash,
        ]);
    }

    public function show(CashSession $session): View
    {
        $session->load(['cashRegister.branch', 'opener', 'closer', 'adjustments.creator']);

        $movements = $session->movements()->with(['category', 'reversal'])->orderBy('created_at')->get();

        return view('finanzas.turno', [
            'session' => $session,
            'movements' => $movements,
            'expected' => $session->status === CashSessionStatus::Abierta ? $session->expectedCash() : $session->expected_cash,
            'adjustedDifference' => $this->cash->adjustedDifference($session),
            'adjustmentsTotal' => Money::round((string) $session->adjustments->sum('amount')),
        ]);
    }

    public function storeRegister(SaveCashRegisterRequest $request): RedirectResponse
    {
        CashRegister::create([
            'branch_id' => $request->validated('branch_id'),
            'name' => $request->validated('name'),
            'status' => RecordStatus::Active->value,
        ]);

        return redirect()->route('finanzas.cajas.index')->with('success', 'Caja creada correctamente.');
    }

    public function updateRegister(SaveCashRegisterRequest $request, CashRegister $register): RedirectResponse
    {
        if ($request->validated('status') === 'inactive'
            && CashSession::query()->open()->where('cash_register_id', $register->id)->exists()) {
            return redirect()->back()->withInput()->withErrors(
                ['status' => 'No se puede desactivar una caja con un turno abierto: cierra primero el turno.'],
                'register'
            );
        }

        $register->update($request->safe()->only(['name', 'status']));

        return redirect()->route('finanzas.cajas.index')->with('success', 'Caja actualizada correctamente.');
    }

    public function open(OpenCashSessionRequest $request): RedirectResponse
    {
        $register = CashRegister::query()->findOrFail($request->validated('cash_register_id'));

        $session = $this->cash->open($register, (string) $request->validated('opening_amount'), $request->user());

        return redirect()->route('finanzas.cajas.show', $session)->with(
            'success',
            'Turno abierto en '.$register->name.' con '.Money::format($session->opening_amount).' iniciales.'
        );
    }

    public function close(CloseCashSessionRequest $request, CashSession $session): RedirectResponse
    {
        $closed = $this->cash->close(
            $session,
            (string) $request->validated('counted_cash'),
            $request->validated('notes'),
            $request->user()
        );

        $diff = Money::cmp($closed->difference, '0') === 0
            ? 'Sin diferencias.'
            : 'Diferencia de '.Money::format($closed->difference).'.';

        return redirect()->route('finanzas.cajas.show', $closed)->with('success', 'Turno cerrado. '.$diff);
    }

    public function adjust(StoreCashAdjustmentRequest $request, CashSession $session): RedirectResponse
    {
        $this->cash->adjust($session, (string) $request->validated('amount'), $request->validated('reason'), $request->user());

        return redirect()->route('finanzas.cajas.show', $session)->with('success', 'Ajuste registrado en el turno.');
    }
}

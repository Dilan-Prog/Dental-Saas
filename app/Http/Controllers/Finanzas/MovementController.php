<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finanzas\ReverseMovementRequest;
use App\Http\Requests\Finanzas\StoreMovementRequest;
use App\Models\FinancialMovement;
use App\Services\Finanzas\MovementService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MovementController extends Controller
{
    public function __construct(private readonly MovementService $movements)
    {
    }

    public function store(StoreMovementRequest $request): RedirectResponse
    {
        $movement = $this->movements->createManual(
            $request->validated(),
            $request->user(),
            $request->file('receipt'),
        );

        return redirect()->route('finanzas.index')->with(
            'success',
            ($movement->direction->label()).' de '.Money::format($movement->amount).' registrado correctamente.'
        );
    }

    public function reverse(ReverseMovementRequest $request, FinancialMovement $movement): RedirectResponse
    {
        $reversal = $this->movements->reverse($movement, $request->validated('reason'), $request->user());

        return redirect()->back()->with(
            'success',
            'Movimiento revertido: se registro un '.mb_strtolower($reversal->direction->label()).' de '.Money::format($reversal->amount).'.'
        );
    }

    /** Comprobante en disco privado: solo con sesion y permiso `finanzas.ver`. */
    public function receipt(FinancialMovement $movement): Response
    {
        abort_unless($movement->receipt_storage_key && Storage::disk(MovementService::DISK)->exists($movement->receipt_storage_key), 404);

        return Storage::disk(MovementService::DISK)->response(
            $movement->receipt_storage_key,
            null,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=0, no-store']
        );
    }
}

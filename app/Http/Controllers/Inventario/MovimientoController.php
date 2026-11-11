<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\AjusteRequest;
use App\Http\Requests\Inventario\EntradaRequest;
use App\Http\Requests\Inventario\InicialRequest;
use App\Http\Requests\Inventario\ReversionRequest;
use App\Http\Requests\Inventario\SalidaRequest;
use App\Models\InventoryMovement;
use App\Services\Inventario\InventarioService;

/**
 * Movimientos de inventario. Son inmutables: aqui solo se crean (entrada, salida,
 * ajuste, inicial) o se revierten con un movimiento contrario. El stock lo
 * actualiza el trigger de la BD.
 */
class MovimientoController extends Controller
{
    public function __construct(private readonly InventarioService $service)
    {
    }

    public function entrada(EntradaRequest $request)
    {
        $m = $this->service->entrada($request->validated());

        return back()->with('success', 'Entrada registrada: ' . $this->describe($m) . '.');
    }

    public function salida(SalidaRequest $request)
    {
        $m = $this->service->salida($request->validated());

        return back()->with('success', 'Salida registrada: ' . $this->describe($m) . '.');
    }

    public function ajuste(AjusteRequest $request)
    {
        $m = $this->service->ajuste($request->validated());

        return back()->with('success', 'Ajuste registrado: ' . $this->describe($m) . '.');
    }

    public function inicial(InicialRequest $request)
    {
        $m = $this->service->inicial($request->validated());

        return back()->with('success', 'Inventario inicial registrado: ' . $this->describe($m) . '.');
    }

    public function reversion(ReversionRequest $request, InventoryMovement $movement)
    {
        $m = $this->service->reversion($movement, $request->validated()['reason']);

        return back()->with('success', 'Movimiento revertido: ' . $this->describe($m) . '.');
    }

    private function describe(InventoryMovement $m): string
    {
        $m->loadMissing('item:id,name,unit_of_measure');
        $qty = rtrim(rtrim(number_format((float) $m->quantity, 4, '.', ''), '0'), '.');

        return ($qty > 0 ? '+' : '') . $qty . ' ' . ($m->item?->unit_of_measure ?? '') . ' de ' . ($m->item?->name ?? 'insumo');
    }
}

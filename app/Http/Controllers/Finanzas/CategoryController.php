<?php

namespace App\Http\Controllers\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finanzas\SaveCategoryRequest;
use App\Models\FinancialCategory;
use App\Services\Finanzas\MovementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = FinancialCategory::query()
            ->withCount('movements')
            ->orderBy('direction')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (FinancialCategory $c) => $c->direction->value);

        return view('finanzas.categorias', [
            'income' => $categories->get(MovementDirection::Ingreso->value, collect()),
            'expense' => $categories->get(MovementDirection::Egreso->value, collect()),
            'directions' => MovementDirection::options(),
        ]);
    }

    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        FinancialCategory::create([
            'name' => $request->validated('name'),
            'direction' => $request->validated('direction'),
            'status' => RecordStatus::Active->value,
        ]);

        return redirect()->route('finanzas.categorias.index')->with('success', 'Categoría creada correctamente.');
    }

    public function update(SaveCategoryRequest $request, FinancialCategory $category): RedirectResponse
    {
        $category->update(['name' => $request->validated('name')]);

        return redirect()->route('finanzas.categorias.index')->with('success', 'Categoría actualizada correctamente.');
    }

    /** Activa / desactiva (las categorias con movimientos nunca se borran). */
    public function toggle(FinancialCategory $category): RedirectResponse
    {
        if (MovementService::isSystemCategoryName($category->name)) {
            return redirect()->route('finanzas.categorias.index')->with('error', 'Las categorías de reversión son del sistema y no se pueden desactivar.');
        }

        $category->update([
            'status' => $category->status === RecordStatus::Active ? RecordStatus::Inactive->value : RecordStatus::Active->value,
        ]);

        return redirect()->route('finanzas.categorias.index')->with(
            'success',
            $category->status === RecordStatus::Active ? 'Categoría activada.' : 'Categoría desactivada: ya no aparecerá en nuevas transacciones.'
        );
    }
}

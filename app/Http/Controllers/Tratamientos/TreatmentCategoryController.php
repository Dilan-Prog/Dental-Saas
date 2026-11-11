<?php

namespace App\Http\Controllers\Tratamientos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tratamientos\TreatmentCategoryRequest;
use App\Models\TreatmentCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

class TreatmentCategoryController extends Controller
{
    public function store(TreatmentCategoryRequest $request): RedirectResponse
    {
        $category = TreatmentCategory::create(['name' => $request->validated('name')]);

        return redirect()->route('tratamientos.index', ['tab' => 'categorias'])
            ->with('success', "Categoría «{$category->name}» creada.");
    }

    public function update(TreatmentCategoryRequest $request, TreatmentCategory $category): RedirectResponse
    {
        $category->update(['name' => $request->validated('name')]);

        return redirect()->route('tratamientos.index', ['tab' => 'categorias'])
            ->with('success', "Categoría «{$category->name}» actualizada.");
    }

    public function destroy(TreatmentCategory $category): RedirectResponse
    {
        if ($category->treatments()->exists()) {
            return redirect()->route('tratamientos.index', ['tab' => 'categorias'])
                ->with('error', "La categoría «{$category->name}» tiene tratamientos asignados. Reasígnalos antes de eliminarla.");
        }

        try {
            $category->delete();
        } catch (QueryException $e) {
            report($e);

            return back()->with('error', 'No se pudo eliminar la categoría.');
        }

        return redirect()->route('tratamientos.index', ['tab' => 'categorias'])
            ->with('success', "Categoría «{$category->name}» eliminada.");
    }
}

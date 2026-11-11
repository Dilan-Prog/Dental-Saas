<?php

namespace App\Http\Controllers\Recetas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recetas\MedicationRequest;
use App\Models\Medication;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Catálogo (vademécum): global + propio de la clínica. */
class MedicationController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $origin = in_array($request->query('origen'), ['global', 'clinica'], true) ? $request->query('origen') : null;
        $status = in_array($request->query('estado'), ['active', 'inactive'], true) ? $request->query('estado') : null;

        $medications = Medication::query()
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('active_ingredient', 'like', $like)->orWhere('presentation', 'like', $like));
            })
            ->when($origin === 'global', fn ($w) => $w->whereNull('organization_id'))
            ->when($origin === 'clinica', fn ($w) => $w->whereNotNull('organization_id'))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->orderBy('name')
            ->orderBy('strength')
            ->paginate(15)
            ->withQueryString();

        return view('recetas.medicamentos.index', [
            'medications' => $medications,
            'filters' => ['q' => $q, 'origen' => $origin, 'estado' => $status],
            'orgId' => Organization::currentId(),
        ]);
    }

    public function store(MedicationRequest $request): RedirectResponse
    {
        $med = Medication::create($request->payload());

        return redirect()->route('recetas.medicamentos.index')->with('success', "Medicamento \"{$med->name}\" agregado al catálogo.");
    }

    public function update(MedicationRequest $request, Medication $medication): RedirectResponse
    {
        if ($medication->organization_id === null) {
            return back()->with('error', 'Los medicamentos del catálogo global no pueden modificarse.');
        }

        $data = $request->payload();
        unset($data['organization_id']);
        $medication->update($data);

        return redirect()->route('recetas.medicamentos.index')->with('success', 'Medicamento actualizado.');
    }

    /** Activa / desactiva un medicamento propio. */
    public function toggle(Medication $medication): RedirectResponse
    {
        if ($medication->organization_id === null) {
            return back()->with('error', 'Los medicamentos del catálogo global no pueden desactivarse.');
        }

        $medication->status = $medication->status->value === 'active' ? 'inactive' : 'active';
        $medication->save();

        return back()->with('success', $medication->status->value === 'active' ? 'Medicamento activado.' : 'Medicamento desactivado.');
    }

    /** Typeahead del formulario de receta (solo activos). */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $rows = Medication::query()
            ->active()
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('active_ingredient', 'like', $like));
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'active_ingredient', 'strength', 'presentation']);

        return response()->json($rows->map(fn (Medication $m) => [
            'id' => $m->id,
            'name' => $m->name,
            'strength' => $m->strength,
            'presentation' => $m->presentation,
            'label' => trim($m->name.($m->strength && ! str_contains($m->name, $m->strength) ? ' '.$m->strength : '').($m->presentation ? ' · '.$m->presentation : '')),
        ])->values());
    }
}

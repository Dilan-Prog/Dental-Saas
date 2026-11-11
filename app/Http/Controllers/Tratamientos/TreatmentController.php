<?php

namespace App\Http\Controllers\Tratamientos;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tratamientos\TreatmentRequest;
use App\Models\TaxProfile;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class TreatmentController extends Controller
{
    private const TABS = ['tratamientos', 'categorias', 'impuestos'];

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'tratamientos';
        $term = trim((string) $request->query('q', ''));
        $estado = in_array($request->query('estado'), ['activos', 'inactivos', 'todos'], true)
            ? $request->query('estado') : 'activos';
        $categoryFilter = Validator::make(['c' => $request->query('categoria')], ['c' => 'uuid'])->passes()
            ? $request->query('categoria') : null;

        $treatments = Treatment::query()
            ->with(['category', 'taxProfile'])
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")->orWhere('sat_product_key', 'like', "%{$term}%");
            }))
            ->when($categoryFilter, fn ($q) => $q->where('category_id', $categoryFilter))
            ->when($estado === 'activos', fn ($q) => $q->where('status', RecordStatus::Active->value))
            ->when($estado === 'inactivos', fn ($q) => $q->where('status', RecordStatus::Inactive->value))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $categories = TreatmentCategory::withCount('treatments')->orderBy('name')->get();
        $taxProfiles = TaxProfile::withCount('treatments')->orderBy('name')->get();

        return view('tratamientos.index', [
            'tab' => $tab,
            'treatments' => $treatments,
            'categories' => $categories,
            'taxProfiles' => $taxProfiles,
            'filters' => ['q' => $term, 'estado' => $estado, 'categoria' => $categoryFilter],
            'defaultTaxProfileId' => $taxProfiles->first(fn ($p) => $p->status === RecordStatus::Active && ! $p->is_exempt)?->id,
        ]);
    }

    public function store(TreatmentRequest $request): RedirectResponse
    {
        $treatment = DB::transaction(fn () => Treatment::create($this->attributes($request) + [
            'currency' => 'MXN',
            'status' => RecordStatus::Active,
        ]));

        return redirect()->route('tratamientos.index')
            ->with('success', "Tratamiento «{$treatment->name}» creado.");
    }

    public function update(TreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        DB::transaction(fn () => $treatment->update($this->attributes($request)));

        return redirect()->route('tratamientos.index', $request->only('page'))
            ->with('success', "Tratamiento «{$treatment->name}» actualizado.");
    }

    /** Activa o desactiva (baja lógica) un tratamiento sin perder el historial. */
    public function toggle(Treatment $treatment): RedirectResponse
    {
        $treatment->status = $treatment->status === RecordStatus::Active ? RecordStatus::Inactive : RecordStatus::Active;
        $treatment->save();

        return back()->with('success', $treatment->status === RecordStatus::Active
            ? "Tratamiento «{$treatment->name}» activado."
            : "Tratamiento «{$treatment->name}» desactivado: ya no aparece en presupuestos ni citas nuevas.");
    }

    /**
     * Quita del catálogo. Si ya se usó en citas, presupuestos o planes se desactiva
     * (las citas y facturas registradas conservan su historial).
     */
    public function destroy(Treatment $treatment): RedirectResponse
    {
        $used = $treatment->quoteItems()->exists()
            || $treatment->appointments()->exists()
            || $treatment->patientTreatments()->exists()
            || \App\Models\InvoiceItem::query()->where('treatment_id', $treatment->id)->exists();

        if ($used) {
            $treatment->update(['status' => RecordStatus::Inactive]);

            return redirect()->route('tratamientos.index')
                ->with('warning', "«{$treatment->name}» ya se usó en presupuestos, citas o facturas, así que se desactivó en lugar de eliminarse.");
        }

        try {
            $treatment->delete();
        } catch (QueryException $e) {
            report($e);

            return back()->with('error', 'No se pudo eliminar el tratamiento porque está relacionado con otros registros. Desactívalo en su lugar.');
        }

        return redirect()->route('tratamientos.index')->with('success', "Tratamiento «{$treatment->name}» eliminado del catálogo.");
    }

    /** @return array<string, mixed> */
    private function attributes(TreatmentRequest $request): array
    {
        $data = $request->validated();

        $categoryId = $data['category_id'] ?? null;
        if (! $categoryId && ($data['category_name'] ?? '') !== '') {
            // La colación de MySQL es insensible a mayúsculas: "preventiva" reutiliza "Preventiva".
            $categoryId = TreatmentCategory::firstOrCreate(['name' => $data['category_name']])->id;
        }

        return [
            'name' => $data['name'],
            'category_id' => $categoryId,
            'duration_minutes' => (int) $data['duration_minutes'],
            'sat_product_key' => $data['sat_product_key'],
            'sat_unit_key' => $data['sat_unit_key'],
            'price' => $data['price'],
            'tax_profile_id' => $data['tax_profile_id'] ?? null,
        ];
    }
}

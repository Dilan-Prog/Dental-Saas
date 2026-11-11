<?php

namespace App\Http\Controllers\Inventario;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\StockSettingsRequest;
use App\Http\Requests\Inventario\StoreItemRequest;
use App\Http\Requests\Inventario\UpdateItemRequest;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Services\Inventario\InventarioReport;
use App\Services\Inventario\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Insumos: alta, edicion, activar/desactivar, configuracion por sucursal y kardex. */
class InsumoController extends Controller
{
    public function __construct(
        private readonly InventarioService $service,
        private readonly InventarioReport $report,
    ) {
    }

    public function store(StoreItemRequest $request)
    {
        $item = $this->service->createItem($request->validated());

        return redirect()->route('inventario.index')
            ->with('success', 'Insumo «' . $item->name . '» registrado.');
    }

    public function update(UpdateItemRequest $request, InventoryItem $item)
    {
        $this->service->updateItem($item, $request->validated());

        return redirect()->route('inventario.items.show', $item)
            ->with('success', 'Insumo actualizado.');
    }

    public function stockSettings(StockSettingsRequest $request, InventoryItem $item)
    {
        $d = $request->validated();
        $this->service->saveStockSettings($item, $d['branch_id'], $d['min_quantity'], $d['location'] ?? null);

        return redirect()->route('inventario.items.show', $item)
            ->with('success', 'Mínimo y ubicación actualizados.');
    }

    public function toggle(InventoryItem $item)
    {
        $item = $this->service->toggleItem($item);

        return back()->with('success', $item->status === RecordStatus::Active
            ? 'Insumo «' . $item->name . '» activado.'
            : 'Insumo «' . $item->name . '» desactivado. Ya no aparece en los movimientos nuevos.');
    }

    /** Ficha del insumo con existencias por sucursal, lotes y kardex con saldo corrido. */
    public function show(Request $request, InventoryItem $item)
    {
        $user = $request->user();
        $branches = $this->report->branches();
        $item->load('supplier:id,name');

        $stockByBranch = DB::table('inventory_stock')->where('item_id', $item->id)->get()->keyBy('branch_id');
        $rows = $branches->map(function ($b) use ($stockByBranch) {
            $s = $stockByBranch->get($b->id);
            $qty = (float) ($s->quantity_on_hand ?? 0);
            $min = (float) ($s->min_quantity ?? 0);
            $cost = (float) ($s->avg_unit_cost ?? 0);

            return (object) [
                'branch' => $b,
                'qty' => $qty,
                'min' => $min,
                'cost' => $cost,
                'value' => $qty * $cost,
                'location' => $s->location ?? null,
                'low' => $min > 0 && $qty <= $min,
                'out' => $min > 0 && $qty <= 0,
                'has_row' => $s !== null,
            ];
        });

        $branchFilter = $request->query('sucursal');
        if ($branchFilter !== 'all' && ! $branches->contains('id', $branchFilter)) {
            $branchFilter = 'all';
        }

        $lots = InventoryLot::query()->where('item_id', $item->id)->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->get();

        $initialByBranch = DB::table('inventory_movements')
            ->where('item_id', $item->id)->where('movement_type', 'inventario_inicial')
            ->pluck('branch_id')->all();

        $data = [
            'item' => $item,
            'branches' => $branches,
            'rows' => $rows,
            'totals' => [
                'qty' => $rows->sum('qty'),
                'value' => $rows->sum('value'),
                'low' => $rows->contains('low', true),
            ],
            'lots' => $lots,
            'kardex' => $this->report->kardex($item, $branchFilter),
            'branchFilter' => $branchFilter,
            'suppliers' => $this->report->suppliers(),
            'categories' => $this->report->categories(),
            'initialByBranch' => $initialByBranch,
            'can' => [
                'create' => $user->hasPermission('inventario', 'crear'),
                'edit' => $user->hasPermission('inventario', 'editar'),
                'toggle' => $user->hasPermission('inventario', 'desactivar'),
                'export' => $user->hasPermission('inventario', 'exportar'),
            ],
        ];

        if ($data['can']['create']) {
            $data += [
                'modalItems' => $this->report->activeItems(),
                'encounters' => $this->report->recentEncounters(),
                'openLots' => $this->report->openLots(),
            ];
        }

        return view('inventario.show', $data);
    }
}

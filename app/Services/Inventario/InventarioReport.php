<?php

namespace App\Services\Inventario;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas del inventario: listado con stock agregado por sucursal, KPIs,
 * transacciones y kardex con saldo corrido.
 *
 * Definiciones:
 *  - "bajo minimo": min_quantity > 0 y existencia <= minimo (en la sucursal elegida, o en alguna si son todas).
 *  - "agotado": existencia <= 0 con minimo > 0 (subconjunto de bajo minimo).
 *  - "por caducar": lotes con existencia > 0 y caducidad dentro de EXPIRY_DAYS dias (o ya vencidos).
 */
class InventarioReport
{
    public const EXPIRY_DAYS = 30;
    public const PER_PAGE = 25;

    /** Sucursal elegida: id valido o 'all'. Por defecto la principal. */
    public function resolveBranch(?string $requested, Collection $branches): string
    {
        if ($requested === 'all') {
            return 'all';
        }
        if ($requested && $branches->contains('id', $requested)) {
            return $requested;
        }

        return (string) ($branches->firstWhere('is_main', true)?->id ?? $branches->first()?->id ?? 'all');
    }

    public function branches(): Collection
    {
        return Branch::query()->where('status', RecordStatus::Active->value)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'is_main']);
    }

    public function suppliers(bool $onlyActive = true): Collection
    {
        return Supplier::query()
            ->when($onlyActive, fn ($q) => $q->where('status', RecordStatus::Active->value))
            ->orderBy('name')->get(['id', 'name', 'status']);
    }

    public function categories(): Collection
    {
        return InventoryItem::query()->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category');
    }

    /** Insumos activos para los selectores de los modales. */
    public function activeItems(): Collection
    {
        return InventoryItem::query()->where('status', RecordStatus::Active->value)
            ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure']);
    }

    /** Consultas recientes (30 dias) para vincular salidas de material. */
    public function recentEncounters(): Collection
    {
        return ClinicalEncounter::query()
            ->with('medicalRecord.patient:id,full_name')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get()
            ->map(fn ($e) => (object) [
                'id' => $e->id,
                'label' => $e->occurred_at->format('d/m H:i') . ' — ' . ($e->medicalRecord?->patient?->full_name ?? 'Paciente'),
            ]);
    }

    /** Lotes con existencia, para el selector de salidas. */
    public function openLots(): Collection
    {
        return InventoryLot::query()->where('quantity_on_hand', '>', 0)
            ->orderBy('expires_on')->get(['id', 'item_id', 'branch_id', 'lot_code', 'expires_on', 'quantity_on_hand']);
    }

    // ------------------------------------------------------------------
    // Resumen
    // ------------------------------------------------------------------

    /** Subconsulta de stock agregado por insumo para la sucursal elegida. */
    private function stockSubquery(string $branch)
    {
        return DB::table('inventory_stock as s')
            ->where('s.organization_id', Organization::currentId())
            ->when($branch !== 'all', fn ($q) => $q->where('s.branch_id', $branch))
            ->select([
                's.item_id',
                DB::raw('SUM(s.quantity_on_hand) as qty'),
                DB::raw('SUM(s.min_quantity) as min_qty'),
                DB::raw('SUM(s.quantity_on_hand * s.avg_unit_cost) as stock_value'),
                DB::raw('MAX(s.avg_unit_cost) as max_cost'),
                DB::raw("GROUP_CONCAT(DISTINCT NULLIF(s.location, '') SEPARATOR ', ') as locations"),
                DB::raw('SUM(CASE WHEN s.min_quantity > 0 AND s.quantity_on_hand <= s.min_quantity THEN 1 ELSE 0 END) as low_rows'),
                DB::raw('SUM(CASE WHEN s.min_quantity > 0 AND s.quantity_on_hand <= 0 THEN 1 ELSE 0 END) as out_rows'),
            ])
            ->groupBy('s.item_id');
    }

    /** Consulta base de insumos con columnas calculadas: stock_qty, min_qty, unit_cost, locations, is_low, is_out. */
    public function itemsQuery(array $f): Builder
    {
        $branch = $f['branch'] ?? 'all';
        $q = trim((string) ($f['q'] ?? ''));

        $query = InventoryItem::query()
            ->leftJoinSub($this->stockSubquery($branch), 'st', 'st.item_id', '=', 'inventory_items.id')
            ->select('inventory_items.*')
            ->addSelect([
                DB::raw('COALESCE(st.qty, 0) as stock_qty'),
                DB::raw('COALESCE(st.min_qty, 0) as min_qty'),
                DB::raw('CASE WHEN COALESCE(st.qty, 0) > 0 THEN st.stock_value / st.qty ELSE COALESCE(st.max_cost, 0) END as unit_cost'),
                DB::raw('st.locations as locations'),
                DB::raw('COALESCE(st.low_rows, 0) > 0 as is_low'),
                DB::raw('COALESCE(st.out_rows, 0) > 0 as is_out'),
            ])
            ->with('supplier:id,name');

        $status = $f['status'] ?? 'active';
        if ($status === 'active') {
            $query->where('inventory_items.status', RecordStatus::Active->value);
        } elseif ($status === 'inactive') {
            $query->where('inventory_items.status', RecordStatus::Inactive->value);
        }

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('inventory_items.name', 'like', $like)->orWhere('inventory_items.sku', 'like', $like));
        }
        if (filled($f['category'] ?? null)) {
            $query->where('inventory_items.category', $f['category']);
        }
        if (filled($f['supplier'] ?? null)) {
            $query->where('inventory_items.supplier_id', $f['supplier']);
        }

        match ($f['filter'] ?? null) {
            'bajo' => $query->whereRaw('COALESCE(st.low_rows, 0) > 0'),
            'agotado' => $query->whereRaw('COALESCE(st.out_rows, 0) > 0'),
            'caducan' => $query->whereExists(function ($sub) use ($branch) {
                $sub->select(DB::raw(1))->from('inventory_lots as l')
                    ->whereColumn('l.item_id', 'inventory_items.id')
                    ->where('l.quantity_on_hand', '>', 0)
                    ->whereNotNull('l.expires_on')
                    ->where('l.expires_on', '<=', now()->addDays(self::EXPIRY_DAYS)->toDateString())
                    ->when($branch !== 'all', fn ($w) => $w->where('l.branch_id', $branch));
            }),
            default => null,
        };

        return $query->orderBy('inventory_items.name');
    }

    public function items(array $f): LengthAwarePaginator
    {
        return $this->itemsQuery($f)->paginate(self::PER_PAGE)->withQueryString();
    }

    /** @return array{count:int,value:string,low:int,out:int,expiring:int,expired:int} */
    public function kpis(string $branch): array
    {
        $agg = DB::query()->fromSub($this->stockSubquery($branch), 'st')
            ->join('inventory_items as i', 'i.id', '=', 'st.item_id')
            ->where('i.status', RecordStatus::Active->value)
            ->selectRaw('COALESCE(SUM(st.stock_value), 0) as value')
            ->selectRaw('COALESCE(SUM(CASE WHEN st.low_rows > 0 THEN 1 ELSE 0 END), 0) as low')
            ->selectRaw('COALESCE(SUM(CASE WHEN st.out_rows > 0 THEN 1 ELSE 0 END), 0) as out_count')
            ->first();

        $lots = InventoryLot::query()
            ->where('quantity_on_hand', '>', 0)
            ->whereNotNull('expires_on')
            ->when($branch !== 'all', fn ($q) => $q->where('branch_id', $branch))
            ->whereHas('item', fn ($q) => $q->where('status', RecordStatus::Active->value));

        $limit = now()->addDays(self::EXPIRY_DAYS)->toDateString();
        $today = now()->toDateString();

        return [
            'count' => InventoryItem::query()->where('status', RecordStatus::Active->value)->count(),
            'value' => bcadd((string) ($agg->value ?? '0'), '0', 2),
            'low' => (int) ($agg->low ?? 0),
            'out' => (int) ($agg->out_count ?? 0),
            'expiring' => (clone $lots)->where('expires_on', '>=', $today)->where('expires_on', '<=', $limit)->count(),
            'expired' => (clone $lots)->where('expires_on', '<', $today)->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Transacciones
    // ------------------------------------------------------------------

    public function movementsQuery(array $f): Builder
    {
        $q = trim((string) ($f['q'] ?? ''));
        $branch = $f['branch'] ?? 'all';

        return InventoryMovement::query()
            ->with(['item:id,name,sku,unit_of_measure', 'branch:id,name', 'actor:id,full_name', 'lot:id,lot_code'])
            ->withExists('reversal')
            ->when($branch !== 'all', fn ($w) => $w->where('branch_id', $branch))
            ->when(filled($f['type'] ?? null), fn ($w) => $w->where('movement_type', $f['type']))
            ->when(filled($f['item'] ?? null), fn ($w) => $w->where('item_id', $f['item']))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $w->whereHas('item', fn ($i) => $i->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($this->date($f['from'] ?? null), fn ($w, $d) => $w->where('occurred_at', '>=', $d->startOfDay()))
            ->when($this->date($f['to'] ?? null), fn ($w, $d) => $w->where('occurred_at', '<=', $d->endOfDay()))
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function movements(array $f): LengthAwarePaginator
    {
        return $this->movementsQuery($f)->paginate(self::PER_PAGE)->withQueryString();
    }

    // ------------------------------------------------------------------
    // Kardex de un insumo
    // ------------------------------------------------------------------

    /** Movimientos del insumo con saldo corrido por sucursal (funcion ventana). */
    public function kardex(InventoryItem $item, ?string $branch, int $perPage = 20): LengthAwarePaginator
    {
        $inner = DB::table('inventory_movements as m')
            ->where('m.organization_id', $item->organization_id)
            ->where('m.item_id', $item->id)
            ->select([
                'm.id', 'm.branch_id', 'm.movement_type', 'm.quantity', 'm.unit_cost', 'm.reference', 'm.reason',
                'm.occurred_at', 'm.actor_user_id', 'm.lot_id', 'm.reverses_movement_id',
                DB::raw('SUM(m.quantity) OVER (PARTITION BY m.branch_id ORDER BY m.occurred_at, m.id) as balance'),
                DB::raw('EXISTS(SELECT 1 FROM inventory_movements r WHERE r.reverses_movement_id = m.id) as is_reversed'),
            ]);

        $page = DB::query()->fromSub($inner, 'k')
            ->when($branch && $branch !== 'all', fn ($q) => $q->where('k.branch_id', $branch))
            ->orderByDesc('k.occurred_at')->orderByDesc('k.id')
            ->paginate($perPage)->withQueryString();

        $users = DB::table('users')->whereIn('id', $page->getCollection()->pluck('actor_user_id')->unique())->pluck('full_name', 'id');
        $branches = DB::table('branches')->whereIn('id', $page->getCollection()->pluck('branch_id')->unique())->pluck('name', 'id');
        $lots = DB::table('inventory_lots')->whereIn('id', $page->getCollection()->pluck('lot_id')->filter()->unique())->pluck('lot_code', 'id');

        $page->getCollection()->transform(function ($r) use ($users, $branches, $lots) {
            $r->type = \App\Enums\InventoryMovementType::from($r->movement_type);
            $r->occurred = Carbon::parse($r->occurred_at);
            $r->actor_name = $users[$r->actor_user_id] ?? '—';
            $r->branch_name = $branches[$r->branch_id] ?? '—';
            $r->lot_code = $r->lot_id ? ($lots[$r->lot_id] ?? null) : null;

            return $r;
        });

        return $page;
    }

    // ------------------------------------------------------------------

    private function date(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
